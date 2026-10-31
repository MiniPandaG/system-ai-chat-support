<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Tenancy\TenancyViolation;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\TenantProbe;

beforeEach(function (): void {
    TenantProbe::createTable();

    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();
    $this->context = app(TenantContext::class);

    $this->context->run($this->tenantA, function (): void {
        TenantProbe::query()->create(['label' => 'a-1']);
        TenantProbe::query()->create(['label' => 'a-2']);
    });
    $this->context->run($this->tenantB, function (): void {
        TenantProbe::query()->create(['label' => 'b-1']);
    });
});

it('solo devuelve las filas de la empresa en contexto', function (): void {
    $seenByA = $this->context->run($this->tenantA, fn (): array => TenantProbe::query()->orderBy('id')->pluck('label')->all());
    $seenByB = $this->context->run($this->tenantB, fn (): array => TenantProbe::query()->pluck('label')->all());

    expect($seenByA)->toBe(['a-1', 'a-2'])
        ->and($seenByB)->toBe(['b-1']);
});

it('sin empresa en contexto no devuelve nada, aunque haya datos', function (): void {
    expect($this->context->id())->toBeNull()
        ->and(TenantProbe::query()->count())->toBe(0)
        ->and(TenantProbe::query()->get())->toHaveCount(0);
});

it('asigna tenant_id al crear a partir del contexto', function (): void {
    $created = $this->context->run($this->tenantA, fn (): TenantProbe => TenantProbe::query()->create(['label' => 'a-3']));

    expect($created->tenant_id)->toBe($this->tenantA->id);
});

it('rechaza tenant_id en una asignación masiva', function (): void {
    expect(fn () => $this->context->run($this->tenantA, fn () => TenantProbe::query()->create(['label' => 'a-3', 'tenant_id' => $this->tenantB->id])))
        ->toThrow(MassAssignmentException::class);
});

it('no crea datos de empresa sin una empresa en contexto', function (): void {
    expect(fn () => TenantProbe::query()->create(['label' => 'huérfana']))->toThrow(TenancyViolation::class);
});

it('no escribe en otra empresa aunque se le asigne a mano', function (): void {
    $probe = new TenantProbe(['label' => 'intrusa']);
    $probe->tenant_id = $this->tenantB->id;

    expect(fn () => $this->context->run($this->tenantA, fn () => $probe->save()))->toThrow(TenancyViolation::class);
});

it('una fila no puede cambiar de empresa', function (): void {
    $this->context->run($this->tenantA, function (): void {
        $probe = TenantProbe::query()->firstOrFail();
        $probe->tenant_id = $this->tenantB->id;

        expect(fn () => $probe->save())->toThrow(TenancyViolation::class);
    });
});

it('pedir por identificador una fila de otra empresa no la encuentra', function (): void {
    $foreignId = $this->context->run($this->tenantB, fn (): int => (int) TenantProbe::query()->value('id'));

    $this->context->run($this->tenantA, function () use ($foreignId): void {
        expect(TenantProbe::query()->find($foreignId))->toBeNull();
        expect(fn () => TenantProbe::query()->findOrFail($foreignId))->toThrow(ModelNotFoundException::class);
    });
});

it('las actualizaciones y borrados masivos solo tocan la empresa actual', function (): void {
    $this->context->run($this->tenantA, function (): void {
        TenantProbe::query()->update(['label' => 'cambiada']);
        TenantProbe::query()->where('label', 'cambiada')->delete();
    });

    $remaining = $this->context->run($this->tenantB, fn (): array => TenantProbe::query()->pluck('label')->all());

    expect($remaining)->toBe(['b-1'])
        ->and($this->context->run($this->tenantA, fn (): int => TenantProbe::query()->count()))->toBe(0);
});

it('el filtro califica la columna con la tabla, así que sobrevive a las uniones', function (): void {
    $sql = $this->context->run($this->tenantA, fn (): string => TenantProbe::query()->toSql());

    expect($sql)->toContain('`tenant_probes`.`tenant_id` = ?');
});
