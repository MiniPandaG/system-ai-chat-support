<?php

declare(strict_types=1);

use App\Ai\CompletionRequest;
use App\Ai\MaskLevel;
use App\Ai\PromptMessage;
use App\Ai\Providers\AnthropicModel;
use App\Ai\Providers\FakeModel;
use App\Ai\Providers\LanguageModels;
use App\Ai\Providers\OpenAiCompatibleModel;
use App\Ai\Providers\ProviderError;
use App\Ai\Providers\ProviderUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const SECRET_KEY = 'clave-ficticia-aaaaaaaaaa';

function openAiModel(string $param = 'max_tokens', bool $json = true, array $extra = []): OpenAiCompatibleModel
{
    return new OpenAiCompatibleModel(app(Factory::class), 'https://api.proveedor.test/v1/', SECRET_KEY, 3, 20, 4096, $param, $json, $extra);
}

function anthropicModel(): AnthropicModel
{
    return new AnthropicModel(app(Factory::class), 'https://api.anthropic.test', SECRET_KEY, 3, 20, 4096);
}

function aiRequest(bool $json = false, ?string $effort = null): CompletionRequest
{
    return new CompletionRequest('modelo-x', 'Reglas fijas.', [new PromptMessage('user', 'Hola', MaskLevel::None), new PromptMessage('assistant', 'Buenas', MaskLevel::None), new PromptMessage('user', '¿Y el envío?', MaskLevel::None)], 300, $json, $effort);
}

function chatBody(string $content = '{"ok":true}', string $finish = 'stop'): array
{
    return ['choices' => [['message' => ['content' => $content], 'finish_reason' => $finish]], 'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 30]];
}

describe('proveedores con el formato de OpenAI', function (): void {
    it('envía el mensaje con la llave en la cabecera, el sistema primero y los campos fijos del proveedor', function (): void {
        Http::fake(['*' => Http::response(chatBody())]);

        $completion = openAiModel(extra: ['thinking' => ['type' => 'disabled']])->complete(aiRequest(json: true));

        expect($completion->text)->toBe('{"ok":true}')->and($completion->inputTokens)->toBe(120)->and($completion->outputTokens)->toBe(30)->and($completion->finishReason)->toBe('stop');
        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://api.proveedor.test/v1/chat/completions'
                && $request->header('Authorization') === ['Bearer '.SECRET_KEY]
                && $request['model'] === 'modelo-x'
                && $request['max_tokens'] === 300
                && $request['thinking'] === ['type' => 'disabled']
                && $request['response_format'] === ['type' => 'json_object']
                && $request['messages'][0] === ['role' => 'system', 'content' => 'Reglas fijas.']
                && array_column($request['messages'], 'role') === ['system', 'user', 'assistant', 'user'];
        });
    });

    it('usa el nombre de campo de tokens que pide el proveedor y no pide JSON si no lo soporta o no se necesita', function (): void {
        Http::fake(['*' => Http::response(chatBody())]);

        openAiModel(param: 'max_completion_tokens')->complete(aiRequest(json: false));
        openAiModel(json: false)->complete(aiRequest(json: true));

        Http::assertSent(fn (Request $request): bool => isset($request['max_completion_tokens']) && ! isset($request['max_tokens']) && ! isset($request['response_format']));
        Http::assertSent(fn (Request $request): bool => isset($request['max_tokens']) && ! isset($request['response_format']));
    });

    it('entiende por qué terminó', function (string $reason, string $expected): void {
        Http::fake(['*' => Http::response(chatBody('texto', $reason))]);

        expect(openAiModel()->complete(aiRequest())->finishReason)->toBe($expected);
    })->with([['stop', 'stop'], ['length', 'length'], ['content_filter', 'refusal'], ['otra_cosa', 'stop']]);

    it('un cuerpo en blanco (lo que dan algunos proveedores en modo JSON sin JSON válido) no es una respuesta', function (mixed $body): void {
        Http::fake(['*' => Http::response($body)]);

        expect(fn () => openAiModel()->complete(aiRequest(json: true)))->toThrow(ProviderUnavailable::class);
    })->with([
        'espacios' => [chatBody('    ')],
        'sin texto' => [['choices' => [['message' => ['content' => null]]]]],
        'sin opciones' => [['choices' => []]],
        'no es lista' => [['error' => 'raro']],
    ]);

    it('el uso ausente o raro cuenta como cero tokens', function (): void {
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'hola']]], 'usage' => ['prompt_tokens' => 'muchos', 'completion_tokens' => -5]])]);

        $completion = openAiModel()->complete(aiRequest());

        expect($completion->inputTokens)->toBe(0)->and($completion->outputTokens)->toBe(0);
    });
});

describe('Anthropic', function (): void {
    it('manda el sistema aparte, la versión y la llave en sus cabeceras y el esfuerzo pedido', function (): void {
        Http::fake(['*' => Http::response(['content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => 'Hola, '], ['type' => 'text', 'text' => 'mundo']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 80, 'output_tokens' => 12]])]);

        $completion = anthropicModel()->complete(aiRequest(effort: 'low'));

        expect($completion->text)->toBe('Hola, mundo')->and($completion->inputTokens)->toBe(80)->and($completion->outputTokens)->toBe(12)->and($completion->finishReason)->toBe('stop');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.anthropic.test/v1/messages'
            && $request->header('x-api-key') === [SECRET_KEY]
            && $request->header('anthropic-version') === ['2023-06-01']
            && $request['system'] === 'Reglas fijas.'
            && $request['model'] === 'modelo-x'
            && $request['max_tokens'] === 300
            && $request['output_config'] === ['effort' => 'low']
            && array_column($request['messages'], 'role') === ['user', 'assistant', 'user']
            && ! isset($request['temperature']));
    });

    it('sin esfuerzo pedido no manda configuración de salida', function (): void {
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);

        anthropicModel()->complete(aiRequest());

        Http::assertSent(fn (Request $request): bool => ! isset($request['output_config']));
    });

    it('entiende el tope de tokens y las negativas, y rechaza una respuesta sin texto', function (): void {
        Http::fake(['*' => Http::sequence()
            ->push(['content' => [['type' => 'text', 'text' => 'cortado']], 'stop_reason' => 'max_tokens'])
            ->push(['content' => [], 'stop_reason' => 'refusal'])
            ->push(['content' => [['type' => 'thinking', 'thinking' => 'x']], 'stop_reason' => 'end_turn'])]);

        expect(anthropicModel()->complete(aiRequest())->finishReason)->toBe('length')
            ->and(anthropicModel()->complete(aiRequest())->finishReason)->toBe('refusal');
        expect(fn () => anthropicModel()->complete(aiRequest()))->toThrow(ProviderUnavailable::class);
    });
});

describe('fallos de cualquier proveedor', function (): void {
    it('cada fallo se reduce a un motivo cerrado y un mensaje fijo, sin la respuesta ni la llave ni la dirección', function (int $status, ProviderError $expected): void {
        Http::fake(['*' => Http::response('Traza interna con '.SECRET_KEY.' en https://api.proveedor.test/secreto', $status)]);

        try {
            openAiModel()->complete(aiRequest());
            $this->fail('Debió fallar.');
        } catch (ProviderUnavailable $error) {
            expect($error->error)->toBe($expected)->and($error->getMessage())->not->toContain(SECRET_KEY)->not->toContain('proveedor.test')->not->toContain('Traza');
        }
    })->with([
        'sin permiso' => [401, ProviderError::Auth],
        'prohibido' => [403, ProviderError::Auth],
        'demasiadas peticiones' => [429, ProviderError::RateLimited],
        'error del servidor' => [500, ProviderError::Server],
        'servicio caído' => [503, ProviderError::Server],
        'petición rechazada' => [400, ProviderError::Rejected],
        'no encontrado' => [404, ProviderError::Rejected],
    ]);

    it('un tiempo agotado es un motivo cerrado y no lleva la llave', function (): void {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 20000 milliseconds with 0 bytes received from '.SECRET_KEY));

        try {
            openAiModel()->complete(aiRequest());
            $this->fail('Debió fallar.');
        } catch (ProviderUnavailable $error) {
            expect($error->error)->toBe(ProviderError::Timeout)->and($error->getMessage())->not->toContain(SECRET_KEY);
        }
    });

    it('sin conexión es un motivo cerrado', function (): void {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect'));

        expect(fn () => anthropicModel()->complete(aiRequest()))->toThrow(ProviderUnavailable::class);
    });

    it('una respuesta demasiado grande se descarta', function (): void {
        Http::fake(['*' => Http::response(chatBody(str_repeat('a', 5000)))]);

        expect(fn () => openAiModel()->complete(aiRequest()))->toThrow(ProviderUnavailable::class);
    });

    it('no sigue redirecciones', function (): void {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);

        expect(fn () => openAiModel()->complete(aiRequest()))->toThrow(ProviderUnavailable::class);
        Http::assertSentCount(1);
    });

    it('sin llave no llama a nadie', function (): void {
        Http::fake();
        $model = new OpenAiCompatibleModel(app(Factory::class), 'https://api.proveedor.test', '', 3, 20, 4096, 'max_tokens', true, []);

        expect(fn () => $model->complete(aiRequest()))->toThrow(ProviderUnavailable::class);
        Http::assertNothingSent();
    });
});

describe('la fábrica de proveedores', function (): void {
    it('arma cada tipo con su configuración', function (): void {
        config(['ai.providers.deepseek.api_key' => SECRET_KEY, 'ai.providers.anthropic.api_key' => SECRET_KEY]);
        $models = app(LanguageModels::class);

        expect($models->for('deepseek'))->toBeInstanceOf(OpenAiCompatibleModel::class)->and($models->for('anthropic'))->toBeInstanceOf(AnthropicModel::class);
    });

    it('un proveedor sin llave no se arma', function (): void {
        config(['ai.providers.gemini.api_key' => '']);

        expect(fn () => app(LanguageModels::class)->for('gemini'))->toThrow(ProviderUnavailable::class);
    });

    it('un proveedor desconocido o de un tipo desconocido es un error, no «sin proveedor»', function (): void {
        config(['ai.providers.raro' => ['driver' => 'otro', 'base_url' => 'https://x.test', 'api_key' => SECRET_KEY]]);

        expect(fn () => app(LanguageModels::class)->for('inexistente'))->toThrow(LogicException::class)
            ->and(fn () => app(LanguageModels::class)->for('raro'))->toThrow(LogicException::class);
    });

    it('el proveedor de prueba es el mismo en todo el proceso y se niega a arrancar en producción', function (): void {
        $fake = app(LanguageModels::class)->for('fake');

        expect($fake)->toBeInstanceOf(FakeModel::class)->and($fake)->toBe(app(FakeModel::class));

        $this->app['env'] = 'production';
        expect(fn () => app(LanguageModels::class)->for('fake'))->toThrow(LogicException::class);
    });

    it('sin AI_FAKE tampoco existe el proveedor de prueba', function (): void {
        config(['ai.fake' => false]);

        expect(fn () => app(LanguageModels::class)->for('fake'))->toThrow(LogicException::class);
    });
});
