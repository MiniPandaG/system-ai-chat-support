<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Models\WidgetOrigin;
use App\Services\AuditLogger;

/**
 * Quita un sitio. Si era el último, el widget deja de mostrarse y las sesiones abiertas dejan de servir.
 */
class RemoveWidgetOrigin
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(User $actor, WidgetOrigin $origin): void
    {
        $id = $origin->id;
        $value = $origin->origin;
        $origin->delete();

        $this->audit->record(AuditEvent::WidgetOriginRemoved, $actor->id, ['origin_id' => $id, 'origin' => $value]);
    }
}
