<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Models\WidgetSession;
use App\Widget\Conversations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La conversación del visitante: solo la de su sesión, y solo mensajes (las notas internas no se consultan).
 */
class ConversationController extends Controller
{
    public function show(Request $request, Conversations $conversations): JsonResponse
    {
        $session = $request->attributes->get('widget_session');
        abort_unless($session instanceof WidgetSession, 401);

        return response()->json($conversations->present($session))->header('Cache-Control', 'no-store');
    }
}
