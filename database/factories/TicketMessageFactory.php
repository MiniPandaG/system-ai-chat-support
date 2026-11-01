<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Tickets\MessageAuthor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketMessage>
 */
class TicketMessageFactory extends Factory
{
    protected $model = TicketMessage::class;

    public function forTicket(Ticket $ticket): static
    {
        return $this->state(fn (): array => ['ticket_id' => $ticket->getKey(), 'tenant_id' => $ticket->tenant_id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['author' => MessageAuthor::Customer, 'body' => fake()->paragraph()];
    }
}
