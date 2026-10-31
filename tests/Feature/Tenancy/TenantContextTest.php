<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\Support\TenantProbe;
use Tests\Support\TenantProbeJob;

/**
 * La carga que el sistema de colas generaría para el job, sin necesitar un trabajador.
 *
 * @return array<string, mixed>
 */
function payloadOf(object $job): array
{
    $queue = app('queue')->connection('sync');

    $json = (fn (): string => $this->createPayload($job, 'default'))->call($queue);

    return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
}

/**
 * @param  array<string, mixed>  $payload
 */
function syncJobFor(array $payload): Job
{
    return new SyncJob(app(), (string) json_encode($payload), 'sync', 'default');
}

beforeEach(function (): void {
    $this->context = app(TenantContext::class);
});

it('run fija la empresa y restablece la anterior al salir, también si falla', function (): void {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    $this->context->run($a, function () use ($a, $b): void {
        expect($this->context->id())->toBe($a->id);

        $this->context->run($b, fn () => expect($this->context->id())->toBe($b->id));

        expect($this->context->id())->toBe($a->id);
    });
    expect($this->context->id())->toBeNull();

    expect(fn () => $this->context->run($a, fn () => throw new RuntimeException('falla')))->toThrow(RuntimeException::class);
    expect($this->context->id())->toBeNull();
});

it('el contexto es de ámbito: otra resolución limpia no hereda la empresa', function (): void {
    $this->context->set(Tenant::factory()->create());

    app()->forgetScopedInstances();

    expect(app(TenantContext::class)->id())->toBeNull();
});

describe('petición', function (): void {
    beforeEach(function (): void {
        Route::middleware('web')->get('/__tenant-probe', fn () => response()->json(['tenant' => app(TenantContext::class)->id()]));
    });

    it('toma la empresa del usuario con sesión', function (): void {
        $user = $this->userInTenant();

        $this->actingAs($user)->getJson('/__tenant-probe')->assertExactJson(['tenant' => $user->tenant_id]);
    });

    it('ignora cualquier empresa que envíe el cliente', function (): void {
        $user = $this->userInTenant();
        $other = Tenant::factory()->create();

        $this->actingAs($user)
            ->withHeader('X-Tenant-Id', (string) $other->id)
            ->getJson('/__tenant-probe?tenant_id='.$other->id)
            ->assertExactJson(['tenant' => $user->tenant_id]);
    });

    it('el personal de la plataforma y los visitantes no tienen empresa', function (): void {
        $staff = User::factory()->create(['is_platform_admin' => true]);

        $this->actingAs($staff)->getJson('/__tenant-probe')->assertExactJson(['tenant' => null]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/__tenant-probe')->assertExactJson(['tenant' => null]);
    });

    it('al terminar la petición el contexto queda vacío', function (): void {
        $this->actingAs($this->userInTenant())->getJson('/__tenant-probe')->assertOk();

        expect(app(TenantContext::class)->id())->toBeNull();
    });

    it('la empresa ya está en contexto cuando se resuelven los modelos de la ruta', function (): void {
        TenantProbe::createTable();
        Route::middleware('web')->get('/__bound/{probe}', fn (TenantProbe $probe) => response()->json(['label' => $probe->label]));

        $owner = $this->userInTenant();
        $intruder = $this->userInTenant();
        $probe = app(TenantContext::class)->run($owner->tenant()->firstOrFail(), fn (): TenantProbe => TenantProbe::query()->create(['label' => 'secreta']));

        $this->actingAs($owner)->getJson('/__bound/'.$probe->id)->assertOk()->assertJson(['label' => 'secreta']);
        $this->assertOtherTenantGets404($intruder, 'GET', '/__bound/'.$probe->id);
    });

    it('el arnés devuelve 404 a otra empresa y 200 a la dueña', function (): void {
        TenantProbe::createTable();
        Route::middleware('web')->get('/__probes/{probe}', fn (int $probe) => TenantProbe::query()->findOrFail($probe));

        $owner = $this->userInTenant();
        $intruder = $this->userInTenant();
        $probe = app(TenantContext::class)->run($owner->tenant()->firstOrFail(), fn (): TenantProbe => TenantProbe::query()->create(['label' => 'secreta']));

        $this->actingAs($owner)->getJson('/__probes/'.$probe->id)->assertOk();
        $this->assertOtherTenantGets404($intruder, 'GET', '/__probes/'.$probe->id);
    });
});

describe('jobs', function (): void {
    beforeEach(function (): void {
        TenantProbeJob::$seen = [];
    });

    it('al encolar guarda la empresa en la carga', function (): void {
        $tenant = Tenant::factory()->create();

        $inside = $this->context->run($tenant, fn (): array => payloadOf(new TenantProbeJob));

        expect($inside['tenant_id'])->toBe($tenant->id)
            ->and(payloadOf(new TenantProbeJob)['tenant_id'])->toBeNull();
    });

    it('al ejecutar restablece la empresa de la carga y después devuelve el contexto anterior', function (): void {
        $tenant = Tenant::factory()->create();
        $payload = $this->context->run($tenant, fn (): array => payloadOf(new TenantProbeJob));
        $job = syncJobFor($payload);

        expect($this->context->id())->toBeNull();

        event(new JobProcessing('sync', $job));
        expect($this->context->id())->toBe($tenant->id);

        event(new JobProcessed('sync', $job));
        expect($this->context->id())->toBeNull();
    });

    it('un job que falla también deja el contexto como estaba', function (): void {
        $tenant = Tenant::factory()->create();
        $job = syncJobFor($this->context->run($tenant, fn (): array => payloadOf(new TenantProbeJob)));

        event(new JobProcessing('sync', $job));
        event(new JobFailed('sync', $job, new RuntimeException('falla')));

        expect($this->context->id())->toBeNull();
    });

    it('un job sin empresa en la carga se ejecuta sin empresa, aunque el trabajador tuviera una', function (): void {
        $leftover = Tenant::factory()->create();
        $job = syncJobFor(payloadOf(new TenantProbeJob));

        $this->context->run($leftover, function () use ($job, $leftover): void {
            event(new JobProcessing('sync', $job));
            expect($this->context->id())->toBeNull();

            event(new JobProcessed('sync', $job));
            expect($this->context->id())->toBe($leftover->id);
        });
    });

    it('un job despachado dentro de una empresa corre con esa empresa', function (): void {
        $tenant = Tenant::factory()->create();

        // El despacho ocurre al destruirse el PendingDispatch: debe terminar dentro de run(), no devolverse de él.
        $this->context->run($tenant, function (): void {
            dispatch(new TenantProbeJob);
        });
        dispatch(new TenantProbeJob);

        expect(TenantProbeJob::$seen)->toBe([$tenant->id, null]);
    });
});

describe('comandos', function (): void {
    it('sin run() un comando no ve datos de empresa y con run() ve solo los de esa empresa', function (): void {
        TenantProbe::createTable();
        $a = Tenant::factory()->create();
        $b = Tenant::factory()->create();

        $this->context->run($a, function (): void {
            TenantProbe::query()->create(['label' => 'a-1']);
            TenantProbe::query()->create(['label' => 'a-2']);
        });
        $this->context->run($b, fn () => TenantProbe::query()->create(['label' => 'b-1']));

        Artisan::command('tenancy:probe', function (): void {
            $this->line((string) TenantProbe::query()->count());
        });

        Artisan::call('tenancy:probe');
        $withoutContext = trim(Artisan::output());

        $withContext = $this->context->run($a, function (): string {
            Artisan::call('tenancy:probe');

            return trim(Artisan::output());
        });

        expect($withoutContext)->toBe('0')->and($withContext)->toBe('2');
    });
});
