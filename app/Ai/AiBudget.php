<?php

declare(strict_types=1);

namespace App\Ai;

use App\Models\AiSettings;
use App\Models\AiUsageDaily;
use App\Models\Tenant;
use App\Plans\Limit;
use App\Plans\PlanLimits;
use App\Tenancy\TenantContext;
use Illuminate\Support\Carbon;

/**
 * El gasto de IA de la empresa en contexto frente a sus topes (RIA-10): el límite diario y el mensual en dólares y los
 * créditos de su plan. Al llegar a cualquiera, la IA se pausa y los tickets quedan para las personas. El control se hace
 * antes de cada llamada; varias llamadas en curso a la vez pueden pasarse del tope por lo que cuesta una respuesta.
 */
class AiBudget
{
    public function __construct(private readonly PlanLimits $plans, private readonly TenantContext $context) {}

    /** Por qué la IA está en pausa, o nulo si puede trabajar. */
    public function pausedReason(AiSettings $settings): ?AiReason
    {
        if ($this->spentToday() >= $this->dailyLimit($settings) || $this->spentThisMonth() >= $this->monthlyLimit($settings)) {
            return AiReason::SpendLimit;
        }

        $tenant = $this->context->current();

        return $tenant !== null && ! $this->plans->allows($tenant, Limit::AiCredits) ? AiReason::Credits : null;
    }

    public function dailyLimit(AiSettings $settings): int
    {
        return $settings->daily_spend_micro ?? config()->integer('ai.limits.daily_spend_micro');
    }

    public function monthlyLimit(AiSettings $settings): int
    {
        return $settings->monthly_spend_micro ?? config()->integer('ai.limits.monthly_spend_micro');
    }

    public function spentToday(): int
    {
        return (int) AiUsageDaily::query()->whereDate('day', $this->today()->toDateString())->sum('cost_micro');
    }

    public function spentThisMonth(): int
    {
        return (int) AiUsageDaily::query()->whereDate('day', '>=', $this->today()->startOfMonth()->toDateString())->sum('cost_micro');
    }

    /**
     * Lo que muestra el panel: cuánto se gastó, el tope y los créditos del plan (nulo si el plan no los limita).
     *
     * @return array{paused: string|null, day: array{spent: int, limit: int}, month: array{spent: int, limit: int}, credits: array{used: int, limit: int|null}}
     */
    public function snapshot(AiSettings $settings, Tenant $tenant): array
    {
        return [
            'paused' => $this->pausedReason($settings)?->value,
            'day' => ['spent' => $this->spentToday(), 'limit' => $this->dailyLimit($settings)],
            'month' => ['spent' => $this->spentThisMonth(), 'limit' => $this->monthlyLimit($settings)],
            'credits' => ['used' => $this->plans->usage($tenant, Limit::AiCredits), 'limit' => $this->plans->limit($tenant, Limit::AiCredits)],
        ];
    }

    /** El consumo se anota por día UTC, igual que en `AiGateway`. */
    private function today(): Carbon
    {
        return now()->utc();
    }
}
