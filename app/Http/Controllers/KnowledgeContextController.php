<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SaveContextRequest;
use App\Knowledge\CompanyContext;
use App\Models\KbContext;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * El contexto de la empresa: qué vende, sus políticas y su tono. Administradores y agentes lo leen; solo los
 * administradores lo escriben (KbContextPolicy). Siempre es el de la empresa de quien lo pide: no se resuelve por
 * identificador.
 */
class KnowledgeContextController extends Controller
{
    public function show(Request $request, CompanyContext $context): Response
    {
        Gate::authorize('view', KbContext::class);

        return Inertia::render('knowledge/Context', [
            'copy' => __('knowledge.context'),
            'body' => $context->text(),
            'can_edit' => $this->user($request)->can('update', KbContext::class),
            'max' => config()->integer('knowledge.context.max'),
        ]);
    }

    public function update(SaveContextRequest $request, CompanyContext $context): RedirectResponse
    {
        $context->save($this->user($request), $request->contextText());

        return back()->with('status', __('knowledge.context.saved'));
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return $user;
    }
}
