<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController as FortifyRecoveryCodeController;

/**
 * Los códigos de recuperación no se pueden volver a ver: la ruta que los listaba en Fortify no existe aquí.
 */
class RecoveryCodeController extends FortifyRecoveryCodeController
{
    public function index(Request $request): never
    {
        abort(404);
    }
}
