<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\StoreWidgetMessageRequest;
use App\Models\Tenant;
use App\Models\WidgetSession;
use App\Widget\Conversations;
use App\Widget\Origins;
use App\Widget\WidgetRejected;
use Illuminate\Http\JsonResponse;

/**
 * El mensaje de un visitante. Sin token abre la sesión y el ticket (la llave pública dice a qué empresa); con token lo
 * añade a su conversación. La respuesta devuelve el token solo cuando la sesión nace aquí.
 */
class MessageController extends Controller
{
    public function store(StoreWidgetMessageRequest $request, Conversations $conversations, Origins $origins): JsonResponse
    {
        $session = $request->attributes->get('widget_session');
        $tenant = $request->attributes->get('widget_tenant');

        if (! $session instanceof WidgetSession || ! $tenant instanceof Tenant) {
            $session = null;
            $tenant = Tenant::query()->where('widget_key', $request->string('key')->toString())->first();

            // Una llave que no existe y un widget apagado reciben lo mismo: no se revela qué empresas lo tienen.
            abort_if($tenant === null || ! $origins->isEnabled($tenant), 404);
        }

        try {
            $sent = $conversations->send(
                $tenant,
                $session,
                (string) $request->ip(),
                $request->string('client_id')->toString(),
                $request->string('body')->toString(),
                $request->name(),
                $request->email(),
            );
        } catch (WidgetRejected $rejected) {
            return response()->json(['message' => $rejected->getMessage()], $rejected->status);
        }

        return response()->json([
            'token' => $sent->token,
            'message' => $conversations->messageFor($sent->message),
        ], $sent->token === null ? 200 : 201);
    }
}
