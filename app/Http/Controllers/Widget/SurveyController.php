<?php

declare(strict_types=1);

namespace App\Http\Controllers\Widget;

use App\Csat\Surveys;
use App\Http\Controllers\Controller;
use App\Http\Requests\Widget\AnswerWidgetSurveyRequest;
use App\Models\WidgetSession;
use Illuminate\Http\JsonResponse;

/**
 * El visitante del widget contesta la encuesta de satisfacción de su conversación. La sesión (su token) identifica su
 * ticket, así que no hace falta ningún enlace. Si no hay una encuesta que contestar, la respuesta es la misma sea cual
 * sea el motivo (ya contestada, caducada o inexistente).
 */
class SurveyController extends Controller
{
    public function store(AnswerWidgetSurveyRequest $request, Surveys $surveys): JsonResponse
    {
        $session = $request->attributes->get('widget_session');
        abort_unless($session instanceof WidgetSession, 401);

        abort_unless($surveys->answerFromWidget($session, $request->score(), $request->comment()), 404);

        return response()->json(['answered' => true]);
    }
}
