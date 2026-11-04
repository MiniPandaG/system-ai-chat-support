<?php

declare(strict_types=1);

require_once __DIR__.'/../Tickets/Helpers.php';

use App\Ai\AiGateway;
use App\Ai\AiPurpose;
use App\Ai\Completion;
use App\Ai\CompletionRequest;
use App\Ai\InputTooLong;
use App\Ai\MaskLevel;
use App\Ai\ModelUnavailable;
use App\Ai\PromptMessage;
use App\Ai\Providers\FakeModel;
use App\Ai\Providers\ProviderError;
use App\Ai\Providers\ProviderUnavailable;
use App\Models\AiCall;
use App\Models\AiUsageDaily;
use App\Models\Tenant;
use App\Tenancy\TenancyViolation;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** @return array<string, mixed> */
function gatewayCall(Tenant $tenant, string $catalogId = 'fake-fast', array $messages = [], ?Closure $handler = null, bool $json = false): mixed
{
    $fake = app(FakeModel::class);
    $fake->handler = $handler;
    $messages = $messages === [] ? [new PromptMessage('user', 'Hola', MaskLevel::Customer)] : $messages;

    return app(TenantContext::class)->run($tenant, fn () => app(AiGateway::class)->complete(AiPurpose::Reply, $catalogId, 'Reglas del código.', $messages, $json));
}

function aiCalls(Tenant $tenant): array
{
    return app(TenantContext::class)->run($tenant, fn (): array => AiCall::query()->orderBy('id')->get()->all());
}

function aiToday(Tenant $tenant): ?AiUsageDaily
{
    return app(TenantContext::class)->run($tenant, fn () => AiUsageDaily::query()->first());
}

describe('la pasarela de IA', function (): void {
    it('llama al modelo, devuelve la respuesta con su costo y registra la llamada y el consumo del día', function (): void {
        $tenant = ticketTenant();

        $result = gatewayCall($tenant, 'fake-fast', handler: fn (CompletionRequest $r): Completion => new Completion('Hola, soy la IA.', 1000, 500, 'stop'));

        // fake-fast: $0,10 por millón de entrada y $0,50 de salida → 100 + 250 millonésimas.
        expect($result->text)->toBe('Hola, soy la IA.')->and($result->costMicro)->toBe(350)->and($result->inputTokens)->toBe(1000)->and($result->outputTokens)->toBe(500)->and($result->finishReason)->toBe('stop');
        $call = aiCalls($tenant)[0];
        expect($call->purpose)->toBe('reply')->and($call->provider)->toBe('fake')->and($call->catalog_id)->toBe('fake-fast')->and($call->status)->toBe('ok')->and($call->error_code)->toBeNull()
            ->and($call->cost_micro)->toBe(350)->and($call->tenant_id)->toBe($tenant->id)->and($call->id)->toBe($result->callId);
        $day = aiToday($tenant);
        expect($day?->calls)->toBe(1)->and($day?->input_tokens)->toBe(1000)->and($day?->output_tokens)->toBe(500)->and($day?->cost_micro)->toBe(350)->and($day?->day->toDateString())->toBe(now()->utc()->toDateString());
    });

    it('varias llamadas suman en la misma fila del día', function (): void {
        $tenant = ticketTenant();

        foreach (range(1, 3) as $_) {
            gatewayCall($tenant, 'fake-fast', handler: fn (CompletionRequest $r): Completion => new Completion('x', 100, 10, 'stop'));
        }

        $rows = app(TenantContext::class)->run($tenant, fn (): int => AiUsageDaily::query()->count());
        expect($rows)->toBe(1)->and(aiToday($tenant)?->calls)->toBe(3)->and(aiToday($tenant)?->input_tokens)->toBe(300);
    });

    it('el consumo es de cada empresa: lo de una no entra en la otra', function (): void {
        $one = ticketTenant();
        $two = ticketTenant();

        gatewayCall($one);
        gatewayCall($one);
        gatewayCall($two);

        expect(aiToday($one)?->calls)->toBe(2)->and(aiToday($two)?->calls)->toBe(1)->and(count(aiCalls($one)))->toBe(2)->and(count(aiCalls($two)))->toBe(1);
    });

    it('enmascara según el nivel antes de enviar: lo del cliente más, lo de la empresa solo lo sensible, el sistema nada', function (): void {
        $tenant = ticketTenant();
        $messages = [
            new PromptMessage('user', 'Mi tarjeta es 4111 1111 1111 1111, cel 3001234567, correo ana@correo.com.', MaskLevel::Customer),
            new PromptMessage('user', 'Contacto: soporte@tienda.com, 3009998888. Cédula 52.123.456.', MaskLevel::Company),
            new PromptMessage('user', 'Texto fijo con 4111111111111111.', MaskLevel::None),
        ];

        gatewayCall($tenant, 'fake-fast', $messages);

        $sent = app(FakeModel::class)->requests[0];
        expect($sent->messages[0]->content)->toBe('Mi tarjeta es [tarjeta], cel [teléfono], correo [correo].')
            ->and($sent->messages[1]->content)->toBe('Contacto: soporte@tienda.com, 3009998888. Cédula [documento].')
            ->and($sent->messages[2]->content)->toBe('Texto fijo con 4111111111111111.')
            ->and($sent->system)->toBe('Reglas del código.');
    });

    it('limpia lo invisible y de dirección del texto antes de enviarlo', function (): void {
        $tenant = ticketTenant();

        gatewayCall($tenant, 'fake-fast', [new PromptMessage('user', "Hola\u{200B} mundo\u{202E}\u{E0041}", MaskLevel::Company)]);

        expect(app(FakeModel::class)->requests[0]->messages[0]->content)->toBe('Hola mundo');
    });

    it('aplica los topes de salida y pasa el modelo, el esfuerzo y el modo JSON del catálogo', function (): void {
        config(['ai.max_output_tokens' => 800, 'ai.providers.anthropic.api_key' => 'sk-x']);
        $tenant = ticketTenant();
        Http::fake(['*' => Http::response(['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 10, 'output_tokens' => 5]])]);

        gatewayCall($tenant, 'fake-fast', json: true);
        app(TenantContext::class)->run($tenant, fn () => app(AiGateway::class)->complete(AiPurpose::Summary, 'claude-fast', 'Reglas.', [new PromptMessage('user', 'Resume', MaskLevel::Customer)], maxTokens: 5000));

        $fake = app(FakeModel::class)->requests[0];
        expect($fake->model)->toBe('fake-fast')->and($fake->maxTokens)->toBe(800)->and($fake->json)->toBeTrue();
        Http::assertSent(fn ($request): bool => $request['model'] === 'claude-haiku-5-5' && $request['max_tokens'] === 800 && $request['output_config'] === ['effort' => 'low']);
    });

    it('un tope de salida menor al de la plataforma gana', function (): void {
        $tenant = ticketTenant();

        app(TenantContext::class)->run($tenant, fn () => app(AiGateway::class)->complete(AiPurpose::Reply, 'fake-fast', 'R.', [new PromptMessage('user', 'Hola', MaskLevel::None)], maxTokens: 200));

        expect(app(FakeModel::class)->requests[0]->maxTokens)->toBe(200);
    });

    it('si lo que se enviaría pasa del tope no llama a nadie ni cobra', function (): void {
        config(['ai.max_input_chars' => 100]);
        $tenant = ticketTenant();
        app(FakeModel::class)->requests = [];

        expect(fn () => gatewayCall($tenant, 'fake-fast', [new PromptMessage('user', str_repeat('a', 200), MaskLevel::None)]))->toThrow(InputTooLong::class);

        expect(app(FakeModel::class)->requests)->toBe([])->and(aiCalls($tenant))->toBe([])->and(aiToday($tenant))->toBeNull();
    });

    it('un modelo que no existe o cuyo proveedor no tiene llave no se llama', function (string $id): void {
        config(['ai.providers.deepseek.api_key' => '']);
        $tenant = ticketTenant();
        Http::fake();

        expect(fn () => gatewayCall($tenant, $id))->toThrow(ModelUnavailable::class);

        Http::assertNothingSent();
        expect(aiCalls($tenant))->toBe([]);
    })->with(['deepseek-fast', 'no-existe', 'openai-fast']);

    it('si el proveedor falla registra el error con su motivo, sin cobrar, y relanza el fallo genérico', function (): void {
        $tenant = ticketTenant();

        try {
            gatewayCall($tenant, 'fake-fast', handler: fn (CompletionRequest $r): Completion => throw new ProviderUnavailable(ProviderError::RateLimited));
            $this->fail('Debió fallar.');
        } catch (ProviderUnavailable $error) {
            expect($error->error)->toBe(ProviderError::RateLimited);
        }

        $call = aiCalls($tenant)[0];
        expect($call->status)->toBe('error')->and($call->error_code)->toBe('rate_limited')->and($call->cost_micro)->toBe(0)->and($call->input_tokens)->toBe(0)
            ->and(aiToday($tenant)?->calls)->toBe(1)->and(aiToday($tenant)?->cost_micro)->toBe(0);
    });

    it('un fallo registra en el log solo proveedor, modelo y motivo, nunca texto del cliente ni la llave', function (): void {
        config(['ai.providers.deepseek.api_key' => 'sk-llave-de-prueba-en-el-log']);
        $tenant = ticketTenant();
        Log::spy();
        Http::fake(['*' => Http::response('Traza con sk-llave-de-prueba-en-el-log y el texto del cliente', 500)]);

        try {
            app(TenantContext::class)->run($tenant, fn () => app(AiGateway::class)->complete(AiPurpose::Reply, 'deepseek-fast', 'R.', [new PromptMessage('user', 'Mi secreto personal del cliente', MaskLevel::Customer)]));
        } catch (ProviderUnavailable) {
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['provider' => 'deepseek', 'catalog' => 'deepseek-fast', 'error' => 'server']
            && ! str_contains($message.json_encode($context), 'sk-llave') && ! str_contains($message.json_encode($context), 'secreto'));
    });

    it('el registro no guarda el texto enviado ni el recibido', function (): void {
        $tenant = ticketTenant();

        gatewayCall($tenant, 'fake-fast', [new PromptMessage('user', 'Texto privado del cliente', MaskLevel::Customer)], fn (CompletionRequest $r): Completion => new Completion('Respuesta privada del modelo', 10, 5, 'stop'));

        $columns = array_keys(aiCalls($tenant)[0]->getAttributes());
        expect(json_encode(aiCalls($tenant)[0]->getAttributes()))->not->toContain('privad')
            ->and($columns)->not->toContain('prompt')->not->toContain('response')->not->toContain('body')->not->toContain('text');
    });

    it('sin empresa en contexto no hay pasarela', function (): void {
        expect(fn () => app(AiGateway::class)->complete(AiPurpose::Reply, 'fake-fast', 'R.', [new PromptMessage('user', 'Hola', MaskLevel::None)]))->toThrow(TenancyViolation::class);
    });

    it('un modelo cortado por el tope se devuelve como tal para que quien lo usa lo trate', function (): void {
        $tenant = ticketTenant();

        $result = gatewayCall($tenant, 'fake-fast', handler: fn (CompletionRequest $r): Completion => new Completion('a medias', 10, 1500, 'length'));

        expect($result->finishReason)->toBe('length');
    });
});
