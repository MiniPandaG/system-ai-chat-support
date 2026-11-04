<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tras iniciar sesión (con o sin segundo factor) siempre se va a la entrada del panel, que reparte según quién sea: la
 * bandeja para el equipo de una empresa y las empresas para el personal de la plataforma. Se descarta la «dirección
 * pendiente» que Laravel guarda en la sesión: quien usa dos cuentas en un mismo navegador (la de plataforma y la de una
 * empresa) terminaba en una página de la otra cuenta, que le respondía 403.
 */
class LoginResponse implements LoginResponseContract, TwoFactorLoginResponseContract
{
    public function toResponse($request): Response
    {
        if ($request instanceof Request) {
            $request->session()->forget('url.intended');
        }

        return $request->wantsJson() ? new JsonResponse('', 204) : redirect('/');
    }
}
