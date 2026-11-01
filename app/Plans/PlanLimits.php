<?php

declare(strict_types=1);

namespace App\Plans;

use App\Enums\Role;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\User;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Único sitio que sabe cuánto permite el plan de una empresa y cuánto usa. Todo lo que añade un recurso limitado pasa
 * por aquí, en el servidor: el cliente no envía ni puede alterar ningún límite.
 */
class PlanLimits
{
    public function __construct(private readonly TenantContext $context) {}

    public function planOf(Tenant $tenant): Plan
    {
        return Plan::query()->findOrFail($tenant->plan_id);
    }

    /** El tope del plan para ese límite; nulo si no tiene. */
    public function limit(Tenant $tenant, Limit $limit): ?int
    {
        return $this->planOf($tenant)->limitFor($limit);
    }

    /**
     * Lo que la empresa usa hoy. Los canales y los créditos de IA aún no existen como recursos (llegan en M7 y M11,
     * que enchufarán aquí su medida), así que por ahora son 0. Los tickets son los abiertos en el mes calendario
     * (hora UTC, la de la aplicación), cualquiera que sea su estado actual.
     */
    public function usage(Tenant $tenant, Limit $limit): int
    {
        return match ($limit) {
            Limit::Agents => $this->agentSeats($tenant),
            Limit::TicketsPerMonth => $this->ticketsThisMonth($tenant),
            default => 0,
        };
    }

    /** Lo que queda antes del tope; nulo si no hay tope. Nunca negativo: ver isOver() para pasarse. */
    public function remaining(Tenant $tenant, Limit $limit): ?int
    {
        $max = $this->limit($tenant, $limit);

        return $max === null ? null : max(0, $max - $this->usage($tenant, $limit));
    }

    /** La empresa ya usa más de lo que permite su plan (por ejemplo, tras bajar de plan). */
    public function isOver(Tenant $tenant, Limit $limit): bool
    {
        $max = $this->limit($tenant, $limit);

        return $max !== null && $this->usage($tenant, $limit) > $max;
    }

    public function allows(Tenant $tenant, Limit $limit, int $adding = 1): bool
    {
        $max = $this->limit($tenant, $limit);

        return $max === null || $this->usage($tenant, $limit) + $adding <= $max;
    }

    /**
     * @throws LimitReached
     */
    public function ensure(Tenant $tenant, Limit $limit, int $adding = 1): void
    {
        $max = $this->limit($tenant, $limit);

        if ($max === null) {
            return;
        }

        $used = $this->usage($tenant, $limit);

        if ($used + $adding > $max) {
            throw LimitReached::for($limit, $this->planOf($tenant)->name, $max, $used, $adding);
        }
    }

    /**
     * Comprueba el límite y ejecuta la acción sin que otra petición pueda colarse en medio: dentro de una transacción
     * se bloquea la fila de la empresa, así dos peticiones simultáneas se turnan y la segunda ve lo que hizo la
     * primera. Con $adding = 0 solo se exige no estar ya por encima del tope.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $action
     * @return TReturn
     *
     * @throws LimitReached
     */
    public function guard(Tenant $tenant, Limit $limit, int $adding, Closure $action): mixed
    {
        return $this->exclusively($tenant, function (Tenant $locked) use ($limit, $adding, $action) {
            $this->ensure($locked, $limit, $adding);

            return $action();
        });
    }

    /**
     * Ejecuta la acción con la fila de la empresa bloqueada, sin comprobar nada. Quien necesite además otro bloqueo
     * (por ejemplo, el de una invitación) debe pedir este primero: así todos bloquean en el mismo orden y no hay
     * esperas cruzadas.
     *
     * @template TReturn
     *
     * @param  Closure(Tenant): TReturn  $action
     * @return TReturn
     */
    public function exclusively(Tenant $tenant, Closure $action): mixed
    {
        return DB::transaction(function () use ($tenant, $action) {
            $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->firstOrFail();

            return $action($locked);
        });
    }

    /** Los roles que ocupan un puesto de agente: los observadores no responden y no cuentan. */
    public static function takesSeat(?Role $role): bool
    {
        return $role === Role::Admin || $role === Role::Agent;
    }

    /**
     * Resumen de todos los límites de una empresa, para mostrarlo.
     *
     * @return array<string, array{limit: int|null, used: int, remaining: int|null, over: bool}>
     */
    public function snapshot(Tenant $tenant): array
    {
        $snapshot = [];

        foreach (Limit::cases() as $limit) {
            $snapshot[$limit->value] = [
                'limit' => $this->limit($tenant, $limit),
                'used' => $this->usage($tenant, $limit),
                'remaining' => $this->remaining($tenant, $limit),
                'over' => $this->isOver($tenant, $limit),
            ];
        }

        return $snapshot;
    }

    /**
     * Miembros activos con puesto más invitaciones abiertas y sin caducar para esos roles (reservan su puesto). Se
     * calcula dentro de la empresa para que el filtro automático cuente solo lo suyo.
     */
    private function agentSeats(Tenant $tenant): int
    {
        $breakdown = $this->agentBreakdown($tenant);

        return $breakdown['members'] + $breakdown['invitations'];
    }

    private function ticketsThisMonth(Tenant $tenant): int
    {
        return $this->context->run($tenant, fn (): int => Ticket::query()->where('created_at', '>=', now()->startOfMonth())->count());
    }

    /**
     * De dónde salen los puestos de agente en uso.
     *
     * @return array{members: int, invitations: int}
     */
    public function agentBreakdown(Tenant $tenant): array
    {
        $roles = [Role::Admin->value, Role::Agent->value];

        return $this->context->run($tenant, fn (): array => [
            'members' => User::query()->inTenant($tenant->id)->whereNull('disabled_at')->whereIn('role', $roles)->count(),
            'invitations' => Invitation::query()->open()->where('expires_at', '>', now())->whereIn('role', $roles)->count(),
        ]);
    }
}
