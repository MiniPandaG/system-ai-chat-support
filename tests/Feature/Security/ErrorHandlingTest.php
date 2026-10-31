<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

it('un error interno no filtra mensajes, rutas ni trazas', function (): void {
    Route::get('/_prueba-error-interno', function (): never {
        throw new RuntimeException('detalle-interno-secreto');
    });

    $this->get('/_prueba-error-interno')
        ->assertStatus(500)
        ->assertDontSee('detalle-interno-secreto', false)
        ->assertDontSee('Stack trace', false)
        ->assertDontSee('/var/www/html', false)
        ->assertDontSee('vendor/laravel', false)
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('la respuesta JSON de error tampoco revela estructura interna', function (): void {
    Route::get('/_prueba-error-json', function (): never {
        throw new RuntimeException('detalle-interno-secreto');
    });

    $response = $this->getJson('/_prueba-error-json')->assertStatus(500);

    expect($response->getContent())
        ->not->toContain('detalle-interno-secreto')
        ->not->toContain('trace')
        ->not->toContain('/var/www/html');
});

it('no expone cabeceras que delatan la tecnología del servidor', function (): void {
    $this->get('/')->assertHeaderMissing('X-Powered-By');
});
