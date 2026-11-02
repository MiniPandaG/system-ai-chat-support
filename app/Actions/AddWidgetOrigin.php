<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WidgetOrigin;
use App\Plans\Limit;
use App\Plans\LimitReached;
use App\Plans\PlanLimits;
use App\Services\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Permite un sitio nuevo para el widget. El primero activa el canal, y activar un canal cuenta contra el plan; el tope
 * de sitios y la repetición se comprueban con la fila de la empresa bloqueada para que dos peticiones simultáneas no se
 * pasen del tope.
 */
class AddWidgetOrigin
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  string  $origin  Ya normalizado por `Origins::normalize`.
     *
     * @throws ValidationException
     * @throws LimitReached
     */
    public function __invoke(User $actor, Tenant $tenant, string $origin): WidgetOrigin
    {
        return $this->limits->exclusively($tenant, function (Tenant $locked) use ($actor, $origin): WidgetOrigin {
            $current = WidgetOrigin::query()->count();

            if ($current >= config()->integer('widget.max_origins')) {
                throw ValidationException::withMessages(['origin' => __('widget.page.errors.max', ['max' => config()->integer('widget.max_origins')])]);
            }

            if (WidgetOrigin::query()->where('origin', $origin)->exists()) {
                throw ValidationException::withMessages(['origin' => __('widget.page.errors.duplicate')]);
            }

            if ($current === 0) {
                $this->limits->ensure($locked, Limit::Channels);
            }

            $row = new WidgetOrigin;
            $row->origin = $origin;
            $row->save();

            $this->audit->record(AuditEvent::WidgetOriginAdded, $actor->id, ['origin_id' => $row->id, 'origin' => $origin]);

            return $row;
        });
    }
}
