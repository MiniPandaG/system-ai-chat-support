<?php

declare(strict_types=1);

namespace App\Ai;

/**
 * El catálogo de modelos (RIA-08, RIA-09): lee la configuración y dice cuáles se pueden usar y cuánto cuesta una respuesta
 * típica. Un modelo está disponible solo si su proveedor tiene llave; los de prueba solo existen fuera de producción.
 */
class ModelCatalog
{
    /** @var array<string, CatalogModel>|null */
    private ?array $models = null;

    /**
     * @return array<string, CatalogModel> Todos los de la configuración, disponibles o no.
     */
    public function all(): array
    {
        return $this->models ??= $this->load();
    }

    /**
     * @return list<CatalogModel>
     */
    public function available(): array
    {
        return array_values(array_filter($this->all(), fn (CatalogModel $model): bool => $model->available));
    }

    public function find(string $id): ?CatalogModel
    {
        return $this->all()[$id] ?? null;
    }

    /** El modelo, solo si existe y se puede usar. */
    public function usable(string $id): ?CatalogModel
    {
        $model = $this->find($id);

        return $model !== null && $model->available ? $model : null;
    }

    /** Lo que costaría una respuesta típica, en millonésimas de dólar (RIA-09). */
    public function estimate(CatalogModel $model): int
    {
        return self::cost($model, config()->integer('ai.estimate.input_tokens'), config()->integer('ai.estimate.output_tokens'));
    }

    /** Lo que cuestan esos tokens con ese modelo, redondeado hacia arriba. */
    public static function cost(CatalogModel $model, int $inputTokens, int $outputTokens): int
    {
        return intdiv($inputTokens * $model->inputMicro + 999_999, 1_000_000) + intdiv($outputTokens * $model->outputMicro + 999_999, 1_000_000);
    }

    /**
     * @return array<string, CatalogModel>
     */
    private function load(): array
    {
        $providers = config()->array('ai.providers');
        $models = [];

        foreach (config()->array('ai.catalog') as $id => $entry) {
            if (! is_string($id) || ! is_array($entry)) {
                continue;
            }

            $name = $entry['provider'] ?? null;
            $provider = is_string($name) ? ($providers[$name] ?? null) : null;

            if (! is_string($name) || ! is_array($provider)) {
                continue;
            }

            $models[$id] = new CatalogModel(
                $id,
                $name,
                is_string($provider['label'] ?? null) ? $provider['label'] : $name,
                is_string($provider['region'] ?? null) ? $provider['region'] : '',
                is_string($entry['model'] ?? null) ? $entry['model'] : '',
                is_string($entry['tier'] ?? null) ? $entry['tier'] : 'fast',
                is_string($entry['label'] ?? null) ? $entry['label'] : $id,
                self::micro($entry['input'] ?? null),
                self::micro($entry['output'] ?? null),
                is_string($entry['effort'] ?? null) ? $entry['effort'] : null,
                is_string($provider['api_key'] ?? null) && $provider['api_key'] !== '',
            );
        }

        return $models + $this->fake();
    }

    /**
     * Los modelos de prueba: solo con `AI_FAKE` y nunca en producción.
     *
     * @return array<string, CatalogModel>
     */
    private function fake(): array
    {
        if (! config()->boolean('ai.fake') || app()->isProduction()) {
            return [];
        }

        return [
            'fake-fast' => new CatalogModel('fake-fast', 'fake', 'Prueba', 'Ninguna (no llama a nadie)', 'fake-fast', 'fast', 'Modelo de prueba rápido', 100_000, 500_000, null, true),
            'fake-precise' => new CatalogModel('fake-precise', 'fake', 'Prueba', 'Ninguna (no llama a nadie)', 'fake-precise', 'precise', 'Modelo de prueba preciso', 2_000_000, 10_000_000, null, true),
        ];
    }

    private static function micro(mixed $dollars): int
    {
        return is_string($dollars) || is_int($dollars) || is_float($dollars) ? (int) round((float) $dollars * 1_000_000) : 0;
    }
}
