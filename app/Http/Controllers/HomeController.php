<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Entrada del panel: el equipo de una empresa llega a su bandeja; el personal de la plataforma, que no tiene empresa,
 * a su panel de plataforma. Un visitante sin sesión pasa por el inicio de sesión desde cualquiera de las dos.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        return redirect()->route($request->user()?->tenant_id === null ? 'platform.tenants' : 'tickets');
    }
}
