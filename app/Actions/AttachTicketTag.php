<?php

declare(strict_types=1);

namespace App\Actions;

use App\Broadcasting\LiveUpdates;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Plans\PlanLimits;
use App\Tickets\TagTone;
use Illuminate\Validation\ValidationException;

/**
 * Pone una etiqueta a un ticket: una que ya existe en la empresa o una nueva creada en el momento. Todo ocurre con la
 * fila de la empresa bloqueada, así los topes de etiquetas se respetan aunque lleguen dos peticiones a la vez y dos
 * personas que crean «VIP» al mismo tiempo acaban con una sola etiqueta.
 */
class AttachTicketTag
{
    public function __construct(private readonly PlanLimits $limits) {}

    /**
     * @param  int|null  $tagId  Etiqueta existente, ya comprobada contra la empresa; si es nulo se usa el nombre.
     *
     * @throws ValidationException
     */
    public function __invoke(Tenant $tenant, Ticket $ticket, ?int $tagId, ?string $name, TagTone $tone): Tag
    {
        return $this->limits->exclusively($tenant, function () use ($ticket, $tagId, $name, $tone): Tag {
            $tag = $tagId !== null ? Tag::query()->findOrFail($tagId) : $this->findOrCreate((string) $name, $tone);

            if ($ticket->tags()->whereKey($tag->id)->exists()) {
                return $tag;
            }

            if ($ticket->tags()->count() >= Tag::MAX_PER_TICKET) {
                throw ValidationException::withMessages(['name' => __('tickets.errors.tags_per_ticket', ['max' => Tag::MAX_PER_TICKET])]);
            }

            $ticket->tags()->attach($tag->id, ['tenant_id' => $ticket->tenant_id]);
            LiveUpdates::both($ticket);

            return $tag;
        });
    }

    private function findOrCreate(string $name, TagTone $tone): Tag
    {
        $existing = Tag::query()->where('name', $name)->first();

        if ($existing !== null) {
            return $existing;
        }

        if (Tag::query()->count() >= Tag::MAX_PER_TENANT) {
            throw ValidationException::withMessages(['name' => __('tickets.errors.tags_per_tenant', ['max' => Tag::MAX_PER_TENANT])]);
        }

        $tag = new Tag(['name' => $name]);
        $tag->forceFill(['tone' => $tone])->save();

        return $tag;
    }
}
