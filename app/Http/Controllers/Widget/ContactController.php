<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\StoreWidgetContactRequest;
use App\Models\WidgetSession;
use App\Widget\Conversations;
use Illuminate\Http\JsonResponse;

/**
 * Deja el nombre y el correo opcionales del visitante. La respuesta es la misma haya o no un cliente con ese correo.
 */
class ContactController extends Controller
{
    public function store(StoreWidgetContactRequest $request, Conversations $conversations): JsonResponse
    {
        $session = $request->attributes->get('widget_session');
        abort_unless($session instanceof WidgetSession, 401);

        $conversations->saveContact($session, $request->name(), $request->email());

        return response()->json(['saved' => true]);
    }
}
