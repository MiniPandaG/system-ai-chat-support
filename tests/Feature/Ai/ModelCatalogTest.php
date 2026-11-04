<?php

declare(strict_types=1);

use App\Ai\ModelCatalog;

describe('el catálogo de modelos', function (): void {
    it('trae dos modelos por proveedor, uno rápido y uno preciso, y los de prueba', function (): void {
        $all = app(ModelCatalog::class)->all();

        foreach (['deepseek', 'claude', 'gemini'] as $family) {
            expect($all["{$family}-fast"]->tier)->toBe('fast')->and($all["{$family}-precise"]->tier)->toBe('precise');
        }
        expect($all)->toHaveKeys(['fake-fast', 'fake-precise']);
    });

    it('un modelo solo está disponible si su proveedor tiene llave', function (): void {
        config(['ai.providers.deepseek.api_key' => 'sk-x', 'ai.providers.anthropic.api_key' => '', 'ai.providers.gemini.api_key' => '']);
        $catalog = app(ModelCatalog::class);

        expect($catalog->usable('deepseek-fast'))->not->toBeNull()
            ->and($catalog->usable('claude-fast'))->toBeNull()
            ->and($catalog->usable('gemini-precise'))->toBeNull()
            ->and($catalog->find('claude-fast')?->available)->toBeFalse()
            ->and($catalog->usable('no-existe'))->toBeNull()
            ->and(array_map(fn ($model): string => $model->id, $catalog->available()))->toContain('deepseek-fast', 'deepseek-precise', 'fake-fast');
    });

    it('cada modelo dice dónde se procesan los textos y cuánto cuesta', function (): void {
        $model = app(ModelCatalog::class)->find('deepseek-fast');

        expect($model?->region)->toBe('China (servidores de DeepSeek)')->and($model?->providerLabel)->toBe('DeepSeek')->and($model?->model)->toBe('deepseek-flash')
            ->and($model?->inputMicro)->toBe(300_000)->and($model?->outputMicro)->toBe(1_200_000);
        expect(app(ModelCatalog::class)->find('claude-fast')?->effort)->toBe('low');
    });

    it('calcula el costo hacia arriba y estima una respuesta típica', function (): void {
        $model = app(ModelCatalog::class)->find('claude-fast');

        // 1000 tokens a $0,10 por millón son 100 millonésimas; 1000 de salida a $0,50 son 500.
        expect(ModelCatalog::cost($model, 1000, 1000))->toBe(600)
            ->and(ModelCatalog::cost($model, 1, 1))->toBe(2)
            ->and(ModelCatalog::cost($model, 0, 0))->toBe(0)
            ->and(app(ModelCatalog::class)->estimate($model))->toBe(ModelCatalog::cost($model, 2500, 350));
    });

    it('un modelo sin precio verificado no entra al catálogo: los de OpenAI esperan a tener llave y precio', function (): void {
        $ids = array_keys(app(ModelCatalog::class)->all());

        expect(array_filter($ids, fn (string $id): bool => str_starts_with($id, 'openai')))->toBe([]);
        foreach (app(ModelCatalog::class)->all() as $model) {
            expect($model->inputMicro)->toBeGreaterThan(0)->and($model->outputMicro)->toBeGreaterThan(0);
        }
    });

    it('los modelos de prueba no existen en producción ni sin AI_FAKE', function (): void {
        config(['ai.fake' => false]);
        expect(app(ModelCatalog::class)->find('fake-fast'))->toBeNull();

        config(['ai.fake' => true]);
        $this->app['env'] = 'production';
        expect(app(ModelCatalog::class)->find('fake-fast'))->toBeNull();
    });

    it('una entrada rota de la configuración se ignora', function (): void {
        config(['ai.catalog.rota' => ['provider' => 'nadie', 'model' => 'x'], 'ai.catalog.otra' => 'texto']);

        expect(app(ModelCatalog::class)->find('rota'))->toBeNull()->and(app(ModelCatalog::class)->find('otra'))->toBeNull();
    });
});
