<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TicketView;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketView>
 */
class TicketViewFactory extends Factory
{
    protected $model = TicketView::class;

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->getKey()]);
    }

    public function ownedBy(User $user): static
    {
        return $this->state(fn (): array => ['user_id' => $user->getKey(), 'tenant_id' => $user->tenant_id]);
    }

    public function shared(): static
    {
        return $this->state(fn (): array => ['shared' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => fake()->unique()->words(2, true), 'shared' => false, 'filters' => ['status' => ['open']]];
    }
}
