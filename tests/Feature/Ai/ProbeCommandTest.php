<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

describe('ai:probe', function (): void {
    it('sin argumento lista el catálogo con su disponibilidad', function (): void {
        config(['ai.providers.deepseek.api_key' => 'sk-x']);

        $this->artisan('ai:probe')->expectsOutputToContain('deepseek-fast')->expectsOutputToContain('disponible')->expectsOutputToContain('sin llave')->assertSuccessful();
    });

    it('comprueba un modelo con una pregunta fija y cuenta tokens sin mostrar la respuesta ni la llave', function (): void {
        config(['ai.providers.deepseek.api_key' => 'sk-llave-secreta-del-probe']);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'listo'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 2]])]);

        $this->artisan('ai:probe', ['modelo' => 'deepseek-fast'])->expectsOutputToContain('12 tokens de entrada')->doesntExpectOutputToContain('sk-llave')->doesntExpectOutputToContain('listo')->assertSuccessful();

        Http::assertSent(fn ($request): bool => $request['messages'][1]['content'] === 'Hola' && $request['model'] === 'deepseek-flash');
    });

    it('un fallo del proveedor se dice con su motivo y termina con error', function (): void {
        config(['ai.providers.deepseek.api_key' => 'sk-x']);
        Http::fake(['*' => Http::response('Traza interna', 401)]);

        $this->artisan('ai:probe', ['modelo' => 'deepseek-fast'])->expectsOutputToContain('Falló: auth.')->doesntExpectOutputToContain('Traza')->assertFailed();
    });

    it('un modelo inexistente o sin llave termina con error y no llama a nadie', function (): void {
        Http::fake();

        $this->artisan('ai:probe', ['modelo' => 'claude-fast'])->assertFailed();
        $this->artisan('ai:probe', ['modelo' => 'nada'])->assertFailed();

        Http::assertNothingSent();
    });
});
