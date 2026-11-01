<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Tenant;
use App\Models\TicketView;
use App\Models\User;
use App\Plans\PlanLimits;
use App\Tickets\InboxFilters;
use Illuminate\Validation\ValidationException;

/**
 * Guarda una combinación de filtros como vista. Con la fila de la empresa bloqueada se respetan los topes de vistas
 * aunque lleguen dos peticiones a la vez. Una búsqueda suelta no se guarda: la vista guarda filtros y orden.
 */
class SaveTicketView
{
    public function __construct(private readonly PlanLimits $limits) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(User $owner, Tenant $tenant, string $name, bool $shared, InboxFilters $filters): TicketView
    {
        $filters = $filters->withoutSearch();

        if (! $filters->isFiltered()) {
            throw ValidationException::withMessages(['name' => __('tickets.errors.view_needs_filter')]);
        }

        return $this->limits->exclusively($tenant, function () use ($owner, $name, $shared, $filters): TicketView {
            if ($shared && TicketView::query()->where('shared', true)->count() >= TicketView::MAX_SHARED_PER_TENANT) {
                throw ValidationException::withMessages(['name' => __('tickets.errors.views_shared_max', ['max' => TicketView::MAX_SHARED_PER_TENANT])]);
            }

            if (! $shared && TicketView::query()->where('shared', false)->where('user_id', $owner->id)->count() >= TicketView::MAX_PRIVATE_PER_USER) {
                throw ValidationException::withMessages(['name' => __('tickets.errors.views_private_max', ['max' => TicketView::MAX_PRIVATE_PER_USER])]);
            }

            $view = new TicketView(['name' => $name]);
            $view->forceFill(['user_id' => $owner->id, 'shared' => $shared, 'filters' => $filters->toArray()])->save();

            return $view;
        });
    }
}
