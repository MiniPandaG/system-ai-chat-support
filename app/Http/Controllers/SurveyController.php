<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Csat\Surveys;
use App\Http\Requests\AnswerSurveyRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * La encuesta de satisfacción que recibe el cliente por correo. Es pública: el enlace con su token es el único permiso.
 * Abrir el enlace nunca registra nada (los escáneres de correo abren todos los enlaces); solo enviar el formulario lo hace.
 * Un enlace falso, caducado o ya usado recibe exactamente la misma página, para no revelar cuál de los casos es.
 */
class SurveyController extends Controller
{
    public function show(Request $request, string $token, Surveys $surveys): Response
    {
        $open = $surveys->open($token);
        $raw = $request->query('score');
        $score = is_string($raw) && preg_match('/\A[1-5]\z/', $raw) === 1 ? (int) $raw : null;

        return $this->private(Inertia::render('survey/Show', [
            'copy' => __('survey.page'),
            'token' => $token,
            'open' => $open !== null,
            'company' => $open['company'] ?? '',
            'theme' => $open['theme'] ?? [],
            'score' => $score,
            'comment_max' => config()->integer('csat.comment_max'),
        ])->toResponse($request));
    }

    public function store(AnswerSurveyRequest $request, string $token, Surveys $surveys): RedirectResponse
    {
        $answered = $surveys->answerByToken($token, $request->score(), $request->comment());

        // Sin importar por qué falló, se vuelve a la misma página, que dice que el enlace ya no sirve.
        return $answered ? redirect()->route('survey.thanks') : redirect()->route('survey.show', ['token' => $token]);
    }

    public function thanks(Request $request): Response
    {
        return $this->private(Inertia::render('survey/Thanks', ['copy' => __('survey.page')])->toResponse($request));
    }

    /** El token viaja en la dirección: ni se guarda en caché ni se manda como referencia a otro sitio. */
    private function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
