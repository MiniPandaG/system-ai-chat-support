<?php

declare(strict_types=1);

namespace App\Ai\Providers;

use App\Ai\Completion;
use App\Ai\CompletionRequest;

/**
 * La API de mensajes de Anthropic (`/v1/messages`). El texto de la respuesta es la unión de sus bloques de texto: los
 * bloques de razonamiento no se usan. Sin relleno previo de la respuesta (los modelos actuales lo rechazan).
 */
final class AnthropicModel extends HttpModel
{
    private const VERSION = '2023-06-01';

    public function complete(CompletionRequest $request): Completion
    {
        $messages = [];

        foreach ($request->messages as $message) {
            $messages[] = ['role' => $message->role, 'content' => $message->content];
        }

        $body = [
            'model' => $request->model,
            'max_tokens' => $request->maxTokens,
            'system' => $request->system,
            'messages' => $messages,
        ];

        if ($request->effort !== null) {
            $body['output_config'] = ['effort' => $request->effort];
        }

        $response = $this->post('/v1/messages', ['x-api-key' => $this->apiKey, 'anthropic-version' => self::VERSION], $body);

        $blocks = $response->json('content');
        $text = '';

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        $stop = $response->json('stop_reason');

        if (trim($text) === '' && $stop !== 'refusal' && $stop !== 'max_tokens') {
            throw new ProviderUnavailable(ProviderError::InvalidResponse);
        }

        return new Completion(
            $text,
            self::tokens($response->json('usage.input_tokens')),
            self::tokens($response->json('usage.output_tokens')),
            match ($stop) {
                'max_tokens' => 'length',
                'refusal' => 'refusal',
                default => 'stop',
            },
        );
    }
}
