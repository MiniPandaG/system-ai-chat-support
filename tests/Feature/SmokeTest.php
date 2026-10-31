<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia;

it('la página principal lleva a la cuenta y, sin sesión, al inicio de sesión', function (): void {
    $this->followingRedirects()->get('/')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('auth/Login'));
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
