<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketNote>
 */
class TicketNoteFactory extends Factory
{
    protected $model = TicketNote::class;

    public function forTicket(Ticket $ticket): static
    {
        return $this->state(fn (): array => ['ticket_id' => $ticket->getKey(), 'tenant_id' => $ticket->tenant_id]);
    }

    public function writtenBy(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['body' => fake()->paragraph()];
    }
}
