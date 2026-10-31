<?php

declare(strict_types=1);

namespace App\Plans;

/**
 * Los recursos que un plan limita. Cada uno nombra la columna de `plans` donde está su tope; añadir un límite nuevo
 * es añadir un caso aquí, su columna y el módulo que mide su uso.
 */
enum Limit: string
{
    case Agents = 'agents';
    case Channels = 'channels';
    case TicketsPerMonth = 'tickets_per_month';
    case AiCredits = 'ai_credits';

    public function column(): string
    {
        return match ($this) {
            self::Agents => 'max_agents',
            self::Channels => 'max_channels',
            self::TicketsPerMonth => 'max_tickets_per_month',
            self::AiCredits => 'ai_credits_per_month',
        };
    }

    /** Los que se cuentan por mes calendario y vuelven a cero al empezar el siguiente. */
    public function isMonthly(): bool
    {
        return $this === self::TicketsPerMonth || $this === self::AiCredits;
    }
}
