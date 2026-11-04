<?php

declare(strict_types=1);

require_once __DIR__.'/../Tickets/Helpers.php';

use App\Enums\AuditEvent;
use App\Knowledge\SourceKind;
use App\Knowledge\Vector\FakeIndex;
use App\Knowledge\Vector\IndexUnavailable;
use App\Knowledge\Vector\VectorIndex;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Privacy\TenantPurge;
use App\Privacy\TenantTables;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TenantFootprint;

beforeEach(function (): void {
    useTemporaryAttachmentDisk();
});

/** @return array<string, int> */
function purgeRowsOf(Tenant $tenant): array
{
    return app(TenantPurge::class)->counts($tenant);
}

function purgeIndex(): FakeIndex
{
    $index = app(VectorIndex::class);
    expect($index)->toBeInstanceOf(FakeIndex::class);

    return $index;
}

describe('borrar una empresa', function (): void {
    it('vacía todas las tablas, los archivos, el índice y las sesiones de esa empresa y la empresa misma', function (): void {
        $doomed = ticketTenant();
        $spared = ticketTenant();
        $one = TenantFootprint::seed($doomed);
        $other = TenantFootprint::seed($spared);
        purgeIndex()->plant($doomed->id, 1, SourceKind::Article, 1, 'texto de la empresa borrada');
        purgeIndex()->plant($spared->id, 1, SourceKind::Article, 1, 'texto de la otra empresa');

        $before = purgeRowsOf($spared);
        $teamBefore = DB::table('sessions')->where('user_id', $other['admin']->id)->count();

        // Si una tabla quedara vacía de antemano, el borrado de esa tabla no estaría demostrado.
        expect(array_keys(array_filter(purgeRowsOf($doomed), fn (int $rows): bool => $rows === 0)))->toBe([]);

        $deleted = app(TenantPurge::class)->purge($doomed);

        expect(array_keys($deleted))->toBe([...TenantTables::PURGE_ORDER, 'users'])
            ->and(array_sum(purgeRowsOf($doomed)))->toBe(0)
            ->and(DB::table('tenants')->where('id', $doomed->id)->exists())->toBeFalse()
            ->and(DB::table('sessions')->where('user_id', $one['admin']->id)->exists())->toBeFalse()
            ->and(DB::table('password_reset_tokens')->where('email', $one['agent']->email)->exists())->toBeFalse()
            ->and(purgeIndex()->exists($doomed->id))->toBeFalse();

        $disk = Storage::disk(config()->string('files.disk'));
        expect($disk->exists($one['attachmentPath']))->toBeFalse()
            ->and($disk->directoryExists("tenants/{$doomed->id}"))->toBeFalse();

        // La otra empresa sigue entera: filas, sesiones, archivos e índice.
        expect(purgeRowsOf($spared))->toBe($before)
            ->and(DB::table('sessions')->where('user_id', $other['admin']->id)->count())->toBe($teamBefore)
            ->and($disk->exists($other['attachmentPath']))->toBeTrue()
            ->and(purgeIndex()->exists($spared->id))->toBeTrue()
            ->and(purgeIndex()->count($spared->id))->toBe(1);
    });

    it('deja una sola auditoría con identificadores y sin nombres ni correos', function (): void {
        $tenant = ticketTenant();
        $seed = TenantFootprint::seed($tenant);

        app(TenantPurge::class)->purge($tenant);

        $entries = AuditLog::query()->where('event', AuditEvent::TenantPurged->value)->get();

        expect($entries)->toHaveCount(1)
            ->and($entries->first()?->context)->toMatchArray(['tenant_id' => $tenant->id, 'via' => 'console'])
            ->and(json_encode($entries->first()?->context))->not->toContain($tenant->name)->not->toContain($seed['agent']->email);
    });

    it('si el índice no responde no se borra nada de la base, y al volver se puede repetir', function (): void {
        $tenant = ticketTenant();
        TenantFootprint::seed($tenant);
        $before = purgeRowsOf($tenant);
        purgeIndex()->down = true;

        expect(fn () => app(TenantPurge::class)->purge($tenant))->toThrow(IndexUnavailable::class);
        expect(purgeRowsOf($tenant))->toBe($before)
            ->and(DB::table('tenants')->where('id', $tenant->id)->exists())->toBeTrue();

        purgeIndex()->down = false;
        app(TenantPurge::class)->purge($tenant);

        expect(array_sum(purgeRowsOf($tenant)))->toBe(0)->and(DB::table('tenants')->where('id', $tenant->id)->exists())->toBeFalse();
    });
});

describe('el comando tenant:purge', function (): void {
    it('con --dry-run cuenta y no borra nada', function (): void {
        $tenant = ticketTenant();
        TenantFootprint::seed($tenant);
        $before = purgeRowsOf($tenant);

        $this->artisan('tenant:purge', ['tenant' => $tenant->id, '--dry-run' => true])
            ->expectsOutputToContain('Prueba en seco')
            ->assertSuccessful();

        expect(purgeRowsOf($tenant))->toBe($before)->and(DB::table('tenants')->where('id', $tenant->id)->exists())->toBeTrue();
    });

    it('sin confirmación o con otro número no borra nada', function (): void {
        $tenant = ticketTenant();
        TenantFootprint::seed($tenant);
        $before = purgeRowsOf($tenant);

        $this->artisan('tenant:purge', ['tenant' => $tenant->id, '--no-interaction' => true])->assertFailed();
        $this->artisan('tenant:purge', ['tenant' => $tenant->id, '--confirm' => (string) ($tenant->id + 1)])->assertFailed();

        expect(purgeRowsOf($tenant))->toBe($before);
    });

    it('con el número correcto borra la empresa', function (): void {
        $tenant = ticketTenant();
        TenantFootprint::seed($tenant);

        $this->artisan('tenant:purge', ['tenant' => $tenant->id, '--confirm' => (string) $tenant->id])
            ->expectsOutputToContain('Listo')
            ->assertSuccessful();

        expect(DB::table('tenants')->where('id', $tenant->id)->exists())->toBeFalse();
    });

    it('una empresa que no existe da un error claro', function (): void {
        $this->artisan('tenant:purge', ['tenant' => 999999999, '--confirm' => '999999999'])->assertFailed();
    });

    it('avisa y no borra si el índice no responde', function (): void {
        $tenant = ticketTenant();
        TenantFootprint::seed($tenant);
        purgeIndex()->down = true;

        $this->artisan('tenant:purge', ['tenant' => $tenant->id, '--confirm' => (string) $tenant->id])->assertFailed();

        expect(DB::table('tenants')->where('id', $tenant->id)->exists())->toBeTrue();
    });

    it('no existe ninguna ruta web que borre o exporte empresas', function (): void {
        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route): string => $route->uri());

        expect($uris->filter(fn (string $uri): bool => str_contains($uri, 'purge') || str_contains($uri, 'export')))->toBeEmpty();
    });
});
