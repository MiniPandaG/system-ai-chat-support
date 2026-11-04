<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Ai\Completion;
use App\Ai\CompletionRequest;
use Illuminate\Http\Client\Factory;

/**
 * Los proveedores que hablan el formato de OpenAI (`/chat/completions`): OpenAI, DeepSeek y Gemini (por su dirección
 * compatible). Lo que cambia entre ellos se configura: el nombre del campo de tokens máximos, el modo JSON y los campos
 * fijos que cada uno pide.
 */
final class OpenAiCompatibleModel extends HttpModel
{
    /**
     * @param  array<string, mixed>  $extraBody
     */
    public function __construct(
        Factory $http,
        string $baseUrl,
        string $apiKey,
        int $connectTimeout,
        int $timeout,
        int $maxResponseBytes,
        private readonly string $maxTokensParam,
        private readonly bool $jsonMode,
        private readonly array $extraBody,
    ) {
        parent::__construct($http, $baseUrl, $apiKey, $connectTimeout, $timeout, $maxResponseBytes);
    }

    public function complete(CompletionRequest $request): Completion
    {
        $messages = [['role' => 'system', 'content' => $request->system]];

        foreach ($request->messages as $message) {
            $messages[] = ['role' => $message->role, 'content' => $message->content];
        }

        $body = [
            ...$this->extraBody,
            'model' => $request->model,
            'messages' => $messages,
            $this->maxTokensParam => $request->maxTokens,
        ];

        if ($request->json && $this->jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $response = $this->post('/chat/completions', ['Authorization' => 'Bearer '.$this->apiKey], $body);

        $text = $response->json('choices.0.message.content');
        $finish = $response->json('choices.0.finish_reason');

        // El modo JSON de algunos proveedores devuelve espacios en blanco si no pudo producir un JSON válido.
        if (! is_string($text) || trim($text) === '') {
            throw new ProviderUnavailable(ProviderError::InvalidResponse);
        }

        return new Completion(
            $text,
            self::tokens($response->json('usage.prompt_tokens')),
            self::tokens($response->json('usage.completion_tokens')),
            match ($finish) {
                'length' => 'length',
                'content_filter' => 'refusal',
                default => 'stop',
            },
        );
    }
}
