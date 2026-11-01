<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tag;
use App\Models\Tenant;
use App\Tickets\TagTone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tag>
 */
class TagFactory extends Factory
{
    protected $model = Tag::class;

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenant->getKey()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['name' => fake()->unique()->word(), 'tone' => TagTone::Neutral];
    }
}
