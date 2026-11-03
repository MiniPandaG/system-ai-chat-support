<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\KbDocument;
use App\Models\User;
use App\Policies\Concerns\ChecksTeamMembership;

/**
 * Igual que los artículos: administradores y agentes leen, solo los administradores suben, editan, publican y borran;
 * los observadores no entran.
 */
class KbDocumentPolicy
{
    use ChecksTeamMembership;

    public function viewAny(User $actor): bool
    {
        return $this->canHandleTickets($actor);
    }

    public function view(User $actor, KbDocument $document): bool
    {
        return $this->viewAny($actor) && $actor->tenant_id === $document->tenant_id;
    }

    public function create(User $actor): bool
    {
        return $this->isAdmin($actor);
    }

    public function update(User $actor, KbDocument $document): bool
    {
        return $this->isAdmin($actor) && $actor->tenant_id === $document->tenant_id;
    }

    public function publish(User $actor, KbDocument $document): bool
    {
        return $this->update($actor, $document);
    }

    public function delete(User $actor, KbDocument $document): bool
    {
        return $this->update($actor, $document);
    }

    private function isAdmin(User $actor): bool
    {
        return $this->isActiveMember($actor) && $actor->role === Role::Admin;
    }
}
