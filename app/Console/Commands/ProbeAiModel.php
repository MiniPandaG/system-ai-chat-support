<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ai\Completion;
use App\Ai\CompletionRequest;
use App\Ai\MaskLevel;
use App\Ai\ModelCatalog;
use App\Ai\PromptMessage;
use App\Ai\Providers\LanguageModels;
use App\Ai\Providers\ProviderUnavailable;
use Illuminate\Console\Command;

/**
 * Comprueba que un modelo del catálogo responde, con una pregunta fija y sin datos de nadie. Es una herramienta de quien
 * opera la plataforma: no guarda nada, no pasa por ninguna empresa y nunca muestra la llave ni la respuesta del proveedor,
 * solo si funcionó, cuánto tardó y los tokens.
 */
class ProbeAiModel extends Command
{
    protected $signature = 'ai:probe {modelo? : Identificador del catálogo (por ejemplo deepseek-fast); sin él, lista el catálogo}';

    protected $description = 'Comprueba que un modelo del catálogo de IA responde';

    public function handle(ModelCatalog $catalog, LanguageModels $models): int
    {
        $id = $this->argument('modelo');

        if (! is_string($id) || $id === '') {
            foreach ($catalog->all() as $entry) {
                $this->line(sprintf('%-18s %-22s %-8s %s', $entry->id, $entry->label, $entry->tier, $entry->available ? 'disponible' : 'sin llave'));
            }

            return self::SUCCESS;
        }

        $entry = $catalog->usable($id);

        if ($entry === null) {
            $this->error('Ese modelo no existe o su proveedor no tiene llave.');

            return self::FAILURE;
        }

        $request = new CompletionRequest($entry->model, 'Responde solo con la palabra: listo', [new PromptMessage('user', 'Hola', MaskLevel::None)], 50, false, $entry->effort);
        $started = hrtime(true);

        try {
            $completion = $models->for($entry->provider)->complete($request);
        } catch (ProviderUnavailable $error) {
            $this->error('Falló: '.$error->error->value.'.');

            return self::FAILURE;
        }

        $this->report($entry->label, $completion, (int) round((hrtime(true) - $started) / 1_000_000));

        return self::SUCCESS;
    }

    private function report(string $label, Completion $completion, int $milliseconds): void
    {
        $this->info("{$label}: respondió en {$milliseconds} ms ({$completion->inputTokens} tokens de entrada, {$completion->outputTokens} de salida, terminó por «{$completion->finishReason}»).");
    }
}
