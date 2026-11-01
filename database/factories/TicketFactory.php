<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Tickets\Channel;
use App\Tickets\TicketPriority;
use App\Tickets\TicketStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tickets de ejemplo con datos inventados. El número lo da quien crea el ticket; la fábrica lo deja libre para que
 * las pruebas puedan fijarlo.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->getKey()]);
    }

    public function forCustomer(Customer $customer): static
    {
        return $this->state(fn (): array => ['customer_id' => $customer->getKey(), 'tenant_id' => $customer->tenant_id]);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'number' => fake()->unique()->numberBetween(1, 1_000_000),
            'subject' => fake()->sentence(6),
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Normal,
            'channel' => Channel::Panel,
            'last_message_at' => now(),
        ];
    }
}
