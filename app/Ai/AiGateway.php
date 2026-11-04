<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Providers\LanguageModels;
use App\Ai\Providers\ProviderUnavailable;
use App\Knowledge\TextCleaner;
use App\Models\AiCall;
use App\Models\AiUsageDaily;
use App\Tenancy\TenancyViolation;
use App\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Único camino hacia un modelo de IA. Por aquí pasa todo lo que sale a un proveedor: se limpia y se enmascara (RS-15), se
 * topa el tamaño, se llama y se registra la llamada y el consumo del día de la empresa en contexto (RF-38). El `system` es
 * texto fijo del código y no se toca; todo lo que escribe una persona o la empresa va en `messages`, con su nivel de
 * enmascarado. Lo que vuelve del modelo es texto no confiable: quien lo reciba debe validarlo.
 */
class AiGateway
{
    public function __construct(
        private readonly ModelCatalog $catalog,
        private readonly LanguageModels $models,
        private readonly PersonalDataMasker $masker,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<PromptMessage>  $messages
     *
     * @throws ModelUnavailable
     * @throws InputTooLong
     * @throws ProviderUnavailable
     */
    public function complete(AiPurpose $purpose, string $catalogId, string $system, array $messages, bool $json = false, ?int $runId = null, ?int $maxTokens = null): AiResult
    {
        $tenantId = $this->context->id();

        if ($tenantId === null) {
            throw TenancyViolation::missingContext(AiCall::class);
        }

        $entry = $this->catalog->usable($catalogId);

        if ($entry === null) {
            throw new ModelUnavailable("El modelo «{$catalogId}» no está disponible.");
        }

        $prepared = array_map(fn (PromptMessage $message): PromptMessage => new PromptMessage(
            $message->role,
            $this->masker->mask(TextCleaner::clean($message->content), $message->mask),
            MaskLevel::None,
        ), $messages);

        $size = strlen($system);

        foreach ($prepared as $message) {
            $size += strlen($message->content);
        }

        if ($size > config()->integer('ai.max_input_chars')) {
            throw new InputTooLong('Lo que se iba a enviar al modelo pasa del tope.');
        }

        $request = new CompletionRequest(
            $entry->model,
            $system,
            $prepared,
            min($maxTokens ?? PHP_INT_MAX, config()->integer('ai.max_output_tokens')),
            $json,
            $entry->effort,
        );

        $started = hrtime(true);

        try {
            $completion = $this->models->for($entry->provider)->complete($request);
        } catch (ProviderUnavailable $error) {
            $this->record($tenantId, $purpose, $entry, $runId, 0, 0, 0, $this->elapsed($started), $error->error->value);
            Log::warning('Una llamada a un modelo de IA falló.', ['provider' => $entry->provider, 'catalog' => $entry->id, 'error' => $error->error->value]);

            throw $error;
        }

        $cost = ModelCatalog::cost($entry, $completion->inputTokens, $completion->outputTokens);
        $call = $this->record($tenantId, $purpose, $entry, $runId, $completion->inputTokens, $completion->outputTokens, $cost, $this->elapsed($started), null);

        return new AiResult($completion->text, $completion->inputTokens, $completion->outputTokens, $cost, $entry->id, $completion->finishReason, $call->id);
    }

    private function record(int $tenantId, AiPurpose $purpose, CatalogModel $entry, ?int $runId, int $inputTokens, int $outputTokens, int $cost, int $latency, ?string $error): AiCall
    {
        return DB::transaction(function () use ($tenantId, $purpose, $entry, $runId, $inputTokens, $outputTokens, $cost, $latency, $error): AiCall {
            $call = new AiCall;
            $call->forceFill([
                'run_id' => $runId,
                'purpose' => $purpose->value,
                'provider' => $entry->provider,
                'catalog_id' => $entry->id,
                'model' => $entry->model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_micro' => $cost,
                'latency_ms' => $latency,
                'status' => $error === null ? 'ok' : 'error',
                'error_code' => $error,
            ])->save();

            $this->bumpDay($tenantId, $inputTokens, $outputTokens, $cost);

            return $call;
        });
    }

    /** Suma la llamada al consumo del día (UTC) de la empresa; el contador de la fila se incrementa en la base, sin carreras. */
    private function bumpDay(int $tenantId, int $inputTokens, int $outputTokens, int $cost): void
    {
        $day = now()->utc()->toDateString();
        $row = AiUsageDaily::query()->where('day', $day)->lockForUpdate()->first();

        if ($row === null) {
            try {
                $row = new AiUsageDaily;
                $row->forceFill(['day' => $day])->save();
            } catch (UniqueConstraintViolationException) {
                // Otra llamada creó la fila del día a la vez: se usa la suya.
                $row = AiUsageDaily::query()->where('day', $day)->lockForUpdate()->firstOrFail();
            }
        }

        $row->increment('calls');
        $row->increment('input_tokens', $inputTokens);
        $row->increment('output_tokens', $outputTokens);
        $row->increment('cost_micro', $cost);
    }

    private function elapsed(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
