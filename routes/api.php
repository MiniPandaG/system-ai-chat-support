<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\TicketController;
use Illuminate\Support\Facades\Route;

// API pública versionada (/api/v1). Sin cookies, sin sesión del panel y sin CSRF: quien llama se identifica con una llave
// de API en `Authorization: Bearer`. La llave fija la empresa; el permiso se exige antes de resolver lo que la ruta nombra.
// La especificación no lleva nada de ninguna empresa: es pública para que las herramientas puedan leerla.
Route::get('/openapi.json', OpenApiController::class)->middleware('throttle:api-docs');

Route::middleware(['api.key', 'throttle:api'])->group(function (): void {
    Route::middleware('api.can:read')->group(function (): void {
        Route::get('/tickets', [TicketController::class, 'index']);
        Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->whereNumber('ticket');
        Route::get('/tickets/{ticket}/messages', [MessageController::class, 'index'])->whereNumber('ticket');
        Route::get('/customers', [CustomerController::class, 'index']);
        Route::get('/customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer');
        Route::get('/agents', [AgentController::class, 'index']);
    });

    Route::middleware(['api.can:write', 'throttle:api-writes', 'api.idempotent'])->group(function (): void {
        Route::post('/tickets', [TicketController::class, 'store']);
        Route::post('/tickets/{ticket}/messages', [MessageController::class, 'store'])->whereNumber('ticket');
        Route::post('/customers', [CustomerController::class, 'store']);
    });

    Route::middleware(['api.can:admin', 'throttle:api-writes'])->group(function (): void {
        Route::patch('/tickets/{ticket}', [TicketController::class, 'update'])->whereNumber('ticket');
    });
});
