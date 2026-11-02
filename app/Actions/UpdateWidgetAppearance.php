<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Models\TenantBranding;
use App\Models\User;
use App\Services\AuditLogger;
use App\Widget\WidgetCorner;
use App\Widget\WidgetMode;

/**
 * Cambia cómo se muestra el widget en el sitio de la empresa. No hace nada, ni audita, si no hay cambios.
 */
class UpdateWidgetAppearance
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function __invoke(User $actor, WidgetMode $mode, WidgetCorner $corner): void
    {
        $branding = TenantBranding::current();
        $branding->widget_mode = $mode;
        $branding->widget_corner = $corner;

        if (! $branding->isDirty()) {
            return;
        }

        $branding->save();

        $this->audit->record(AuditEvent::WidgetAppearanceChanged, $actor->id, ['mode' => $mode->value, 'corner' => $corner->value]);
    }
}
