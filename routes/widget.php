<?php

declare(strict_types=1);

use App\Http\Controllers\Widget\BroadcastAuthController;
use App\Http\Controllers\Widget\ContactController;
use App\Http\Controllers\Widget\ConversationController;
use App\Http\Controllers\Widget\FrameController;
use App\Http\Controllers\Widget\LogoController;
use App\Http\Controllers\Widget\MessageController;
use App\Http\Controllers\Widget\SurveyController;
use Illuminate\Support\Facades\Route;

// La API del widget no usa cookies ni la sesión del panel: el visitante se identifica con un token en la cabecera
// Authorization, así que no hay credencial ambiente que un sitio ajeno pueda aprovechar (CSRF). Todo vive bajo /widget.
Route::get('/frame', [FrameController::class, 'show'])->middleware('throttle:widget-frame');
Route::get('/logo', [LogoController::class, 'show'])->middleware('throttle:widget-frame');

Route::prefix('api')->group(function (): void {
    Route::post('/messages', [MessageController::class, 'store'])
        ->middleware(['throttle:widget-message', 'widget.session:optional']);

    Route::middleware('widget.session')->group(function (): void {
        Route::get('/conversation', [ConversationController::class, 'show'])->middleware('throttle:widget-read');
        Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:widget-contact');
        Route::post('/survey', [SurveyController::class, 'store'])->middleware('throttle:widget-survey');
        Route::post('/broadcasting/auth', [BroadcastAuthController::class, 'store'])->middleware('throttle:widget-auth');
    });
});
