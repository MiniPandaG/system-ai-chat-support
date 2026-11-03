<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\KbArticle;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamMembership;

/**
 * El conocimiento alimenta a la IA, así que se gestiona con el mínimo privilegio: solo los administradores crean, editan,
 * publican y borran; los agentes lo leen; los observadores no entran.
 */
class KbArticlePolicy
{
    use ChecksTeamMembership;

    public function viewAny(User $actor): bool
    {
        return $this->canHandleTickets($actor);
    }

    public function view(User $actor, KbArticle $article): bool
    {
        return $this->viewAny($actor) && $actor->tenant_id === $article->tenant_id;
    }

    public function create(User $actor): bool
    {
        return $this->isAdmin($actor);
    }

    public function update(User $actor, KbArticle $article): bool
    {
        return $this->isAdmin($actor) && $actor->tenant_id === $article->tenant_id;
    }

    public function publish(User $actor, KbArticle $article): bool
    {
        return $this->update($actor, $article);
    }

    public function delete(User $actor, KbArticle $article): bool
    {
        return $this->update($actor, $article);
    }

    private function isAdmin(User $actor): bool
    {
        return $this->isActiveMember($actor) && $actor->role === Role::Admin;
    }
}
