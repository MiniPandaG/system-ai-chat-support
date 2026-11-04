<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\Factory;
use LogicException;

/**
 * Arma el cliente de cada proveedor con su configuración. Es el único lugar de la aplicación que lee las llaves: ninguna
 * otra parte las ve, las imprime ni las guarda. El proveedor de prueba se niega a arrancar en producción y un valor
 * desconocido nunca se interpreta como «sin proveedor».
 */
class LanguageModels
{
    public function __construct(private readonly Container $container) {}

    /**
     * @throws ProviderUnavailable
     */
    public function for(string $provider): LanguageModel
    {
        if ($provider === 'fake') {
            if (! config()->boolean('ai.fake') || app()->isProduction()) {
                throw new LogicException('El proveedor de prueba no es válido en este entorno.');
            }

            return $this->container->make(FakeModel::class);
        }

        $settings = config()->array('ai.providers')[$provider] ?? null;

        if (! is_array($settings)) {
            throw new LogicException("El proveedor de IA «{$provider}» no existe.");
        }

        $key = is_string($settings['api_key'] ?? null) ? $settings['api_key'] : '';
        $url = is_string($settings['base_url'] ?? null) ? $settings['base_url'] : '';
        $http = config()->array('ai.http');
        $http = [
            'connect' => is_int($http['connect_timeout'] ?? null) ? $http['connect_timeout'] : 5,
            'timeout' => is_int($http['timeout'] ?? null) ? $http['timeout'] : 60,
            'max' => is_int($http['max_response_bytes'] ?? null) ? $http['max_response_bytes'] : 1048576,
        ];

        if ($key === '') {
            throw new ProviderUnavailable(ProviderError::NotConfigured);
        }

        return match ($settings['driver'] ?? null) {
            'openai' => new OpenAiCompatibleModel(
                $this->container->make(Factory::class),
                $url,
                $key,
                $http['connect'],
                $http['timeout'],
                $http['max'],
                is_string($settings['max_tokens_param'] ?? null) ? $settings['max_tokens_param'] : 'max_tokens',
                (bool) ($settings['json_mode'] ?? false),
                is_array($settings['extra_body'] ?? null) ? array_filter($settings['extra_body'], is_string(...), ARRAY_FILTER_USE_KEY) : [],
            ),
            'anthropic' => new AnthropicModel($this->container->make(Factory::class), $url, $key, $http['connect'], $http['timeout'], $http['max']),
            default => throw new LogicException("El proveedor de IA «{$provider}» tiene un tipo que no se conoce."),
        };
    }
}
