<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Broadcasting\Channels;
use App\Http\Controllers\Controller;
use App\Models\WidgetSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Firma la entrada del visitante a su propio canal de tiempo real y a ningún otro. No usa la sesión del panel: la
 * identidad es el token de la sesión del widget, y el único nombre de canal que se firma es el que sale de esa sesión.
 */
class BroadcastAuthController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $session = $request->attributes->get('widget_session');
        $socket = $request->input('socket_id');
        $channel = $request->input('channel_name');

        abort_unless(
            $session instanceof WidgetSession
            && is_string($socket) && preg_match('/\A\d{1,20}\.\d{1,20}\z/', $socket) === 1
            && is_string($channel) && hash_equals('private-'.Channels::widget($session->tenant_id, $session->id), $channel),
            403,
        );

        $key = config()->string('broadcasting.connections.reverb.key');
        $secret = config()->string('broadcasting.connections.reverb.secret');

        abort_if($key === '' || $secret === '', 403);

        return response()->json(['auth' => $key.':'.hash_hmac('sha256', "{$socket}:{$channel}", $secret)]);
    }
}
