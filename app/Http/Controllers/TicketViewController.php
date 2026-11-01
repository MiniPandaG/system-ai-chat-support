<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SaveTicketView;
use App\Http\Requests\StoreTicketViewRequest;
use App\Models\TicketView;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Vistas guardadas de la bandeja. La vista se busca entre las que la persona puede ver: la privada de otra persona da 404
 * igual que una que no existe, así no se revela que existe.
 */
class TicketViewController extends Controller
{
    public function store(StoreTicketViewRequest $request, SaveTicketView $save, TenantContext $context): RedirectResponse
    {
        $owner = $request->user();
        $tenant = $context->current();
        abort_unless($owner instanceof User && $tenant !== null, 403);

        $view = $save($owner, $tenant, $request->string('name')->toString(), $request->boolean('shared'), $request->filters());

        return redirect()->route('tickets', ['saved' => $view->id])->with('status', __('tickets.views.saved'));
    }

    public function destroy(Request $request, int $view): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $found = TicketView::query()->visibleTo($user)->findOrFail($view);
        Gate::authorize('delete', $found);
        $found->delete();

        return redirect()->route('tickets')->with('status', __('tickets.views.deleted'));
    }
}
