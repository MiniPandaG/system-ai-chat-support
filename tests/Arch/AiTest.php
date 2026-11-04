<?php

declare(strict_types=1);

require_once __DIR__.'/SourceRulesTest.php';

arch('los proveedores de IA solo los usan la propia capa de IA, la herramienta de comprobación y el registro de servicios')
    ->expect('App\Ai\Providers')
    ->toOnlyBeUsedIn(['App\Ai', 'App\Console\Commands\ProbeAiModel', 'App\Providers\AppServiceProvider']);

it('las llaves de los proveedores de IA solo las leen el catálogo y la fábrica de proveedores', function (): void {
    $files = sourceFiles(['app', 'routes', 'resources/views', 'resources/js'], ['php', 'tsx', 'ts']);

    $hits = filesMatching($files, '/ai\.providers/', ['app/Ai/ModelCatalog.php', 'app/Ai/Providers/LanguageModels.php']);

    expect($hits)->toBe([]);
});

it('ninguna parte de la aplicación imprime ni registra una llave de IA', function (): void {
    $files = sourceFiles(['app'], ['php']);

    expect(filesMatching($files, '/(?:Log::|logger\(|report\(|dump\(|var_dump\(|print_r\().*(?:api_key|apiKey|Authorization)/i'))->toBe([]);
});

// La IA no tiene herramientas: lo único que el código de IA puede hacer sobre un ticket es lo que escribe `ReplyFlow` y
// `Escalator` con estas dos acciones, y solo después de pasar las reglas. Ningún otro punto de la aplicación las usa.
arch('solo el flujo de respuesta y el escalamiento envían mensajes de la IA y dejan sus notas')
    ->expect('App\Actions\SendAiMessage')->toOnlyBeUsedIn(['App\Ai\ReplyFlow', 'App\Ai\Escalator']);

arch('solo el escalamiento deja notas de la IA')
    ->expect('App\Actions\AddAiNote')->toOnlyBeUsedIn(['App\Ai\Escalator']);

arch('solo la pasarela habla con un modelo: la usan la generación de respuestas, el escalamiento y la herramienta de comprobación')
    ->expect('App\Ai\AiGateway')->toOnlyBeUsedIn(['App\Ai', 'App\Console\Commands\ProbeAiModel']);

arch('solo el disparo y el trabajo ponen en marcha el flujo de respuesta')
    ->expect('App\Ai\ReplyFlow')->toOnlyBeUsedIn(['App\Ai', 'App\Jobs\GenerateAiReply']);

arch('solo los canales de entrada de mensajes del cliente disparan a la IA')
    ->expect('App\Ai\AiReplies')->toOnlyBeUsedIn(['App\Widget\Conversations', 'App\Mail\Inbound\InboundMailProcessor', 'App\Ai']);

it('el código de IA no usa ninguna otra acción de la aplicación: no cierra, asigna, etiqueta ni borra nada', function (): void {
    $files = sourceFiles(['app/Ai'], ['php']);

    expect(filesMatching($files, '/use App\\\\Actions\\\\(?!SendAiMessage;|AddAiNote;)/'))->toBe([]);
});

it('el código de IA no hace llamadas de red fuera de la capa de proveedores', function (): void {
    $files = array_filter(sourceFiles(['app/Ai'], ['php']), fn (string $path): bool => ! str_contains(str_replace('\\', '/', $path), 'app/Ai/Providers/'), ARRAY_FILTER_USE_KEY);

    expect($files)->not->toBe([])->and(filesMatching($files, '/Http::|Illuminate\\\\Support\\\\Facades\\\\Http|file_get_contents\(\s*[\'"]https?|curl_/'))->toBe([]);
});

it('el código de IA no escribe en los tickets ni en los clientes por su cuenta', function (): void {
    $files = sourceFiles(['app/Ai'], ['php']);

    expect(filesMatching($files, '/\b(?:Ticket|Customer|Tag|TicketMessage)::(?:create|insert|update|upsert|destroy)\(|->(?:delete|forceDelete)\(\)/', ['app/Ai/Reply/ReplyGenerator.php']))->toBe([]);
});
