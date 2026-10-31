<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            // Contraseña aleatoria en cada creación; las pruebas que la necesitan pasan la suya con password().
            'password' => Str::random(32),
            'is_platform_admin' => false,
            'tenant_id' => null,
            'role' => null,
            'disabled_at' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (): array => ['email_verified_at' => null]);
    }

    public function forTenant(Tenant $tenant, Role $role = Role::Agent): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->getKey(), 'role' => $role]);
    }

    public function password(string $password): static
    {
        return $this->state(fn (): array => ['password' => $password]);
    }
}
