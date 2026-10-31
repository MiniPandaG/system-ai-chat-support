<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;

it('muestra la página base con textos en español', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Base')
            ->where('copy.heading', 'Base del proyecto en marcha'));
});

it('responde al chequeo de salud sin exponer detalles internos', function (): void {
    $this->get('/up')->assertOk();
});

it('devuelve 404 sin trazas en una ruta inexistente', function (): void {
    $this->get('/ruta-que-no-existe')
        ->assertNotFound()
        ->assertDontSee('vendor/laravel', false)
        ->assertDontSee('Stack trace', false);
});
