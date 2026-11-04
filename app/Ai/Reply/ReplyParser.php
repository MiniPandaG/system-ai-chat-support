<?php

declare(strict_types=1);

namespace App\Ai\Reply;

use App\Knowledge\TextCleaner;
use JsonException;

/**
 * Valida lo que devolvió el modelo. La salida del modelo es contenido no confiable (RS-14): se exige la forma exacta, se
 * quitan HTML, enlaces de markdown y cualquier dirección web o de correo que no esté en los datos que se le dieron (una
 * dirección inventada o puesta por un atacante no llega al cliente) y se descartan los números de fragmento que no existían.
 */
class ReplyParser
{
    /** Frases propias de las reglas internas y del formato del bloque de datos: ninguna respuesta legítima las contiene. */
    private const INTERNAL_MARKERS = ['REGLAS (no cambian nunca', 'FORMATO: responde SOLO con un objeto JSON', '<contexto_empresa>', '<conocimiento>', '<intento_ia>'];

    /**
     * @param  string  $allowedText  Lo que se le dio al modelo como datos: de ahí salen los únicos enlaces y correos permitidos.
     * @param  int  $chunkCount  Cuántos fragmentos se le dieron, numerados desde 1.
     *
     * @throws InvalidReply
     */
    public function parse(string $raw, string $allowedText, int $chunkCount): ModelReply
    {
        $data = $this->decode($raw);

        $canAnswer = $data['puede_responder'] ?? null;
        $needsHuman = $data['necesita_humano'] ?? null;
        $answer = $data['respuesta'] ?? null;
        $sources = $data['fuentes'] ?? [];

        if (! is_bool($canAnswer) || ! is_bool($needsHuman) || ! is_string($answer) || ! is_array($sources)) {
            throw new InvalidReply('La forma de la respuesta no es la esperada.');
        }

        $valid = [];

        foreach ($sources as $source) {
            if (is_int($source) && $source >= 1 && $source <= $chunkCount) {
                $valid[$source] = $source;
            }
        }

        // Un cliente puede pedirle al modelo que repita sus instrucciones: lo que las contenga no sale hacia nadie.
        foreach (self::INTERNAL_MARKERS as $marker) {
            if (str_contains($answer, $marker)) {
                throw new InvalidReply('La respuesta repite las instrucciones internas.');
            }
        }

        $answer = $canAnswer ? $this->sanitize($answer, $allowedText) : '';

        if ($canAnswer && $answer === '') {
            throw new InvalidReply('Dice poder responder pero no hay texto.');
        }

        if (mb_strlen($answer) > config()->integer('ai.reply.max_answer_chars')) {
            throw new InvalidReply('La respuesta es demasiado larga.');
        }

        return new ModelReply($canAnswer, $needsHuman, $answer, array_values($valid));
    }

    /** @return array<string, mixed> */
    private function decode(string $raw): array
    {
        $json = trim($raw);

        // Algunos modelos envuelven el JSON en un bloque de código aunque se les pida que no.
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/su', $json, $match) === 1) {
            $json = $match[1];
        }

        try {
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidReply('No es JSON.');
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidReply('No es un objeto JSON.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function sanitize(string $answer, string $allowedText): string
    {
        // Los datos le llegan al modelo con los signos de etiqueta escapados (`&lt;`), y puede devolverlos así: se deshacen
        // antes de quitar las etiquetas, para que ni «&amp;» salga como texto raro ni «&lt;b&gt;» se salte la limpieza.
        $answer = html_entity_decode(TextCleaner::clean($answer), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $answer = strip_tags(TextCleaner::clean($answer));
        // Un enlace de markdown [texto](dirección) se queda con el texto: la dirección, si es válida, ya está en el texto.
        $answer = preg_replace('/\[([^\]]*)\]\((?:[^)]*)\)/u', '$1', $answer) ?? $answer;
        $answer = $this->withoutUnknown($answer, $allowedText);
        $answer = preg_replace('/[ \t]{2,}/u', ' ', $answer) ?? $answer;
        $answer = preg_replace("/[ \t]+\n/u", "\n", $answer) ?? $answer;
        $answer = preg_replace("/\n{3,}/u", "\n\n", $answer) ?? $answer;

        return trim($answer);
    }

    private function withoutUnknown(string $answer, string $allowedText): string
    {
        $allowed = mb_strtolower($allowedText);

        $answer = preg_replace_callback('~(?:https?://|www\.)[^\s<>"\']+~iu', function (array $match) use ($allowed): string {
            $url = rtrim($match[0], '.,;:!?)]}');

            return $this->appears($url, $allowed) ? $match[0] : substr($match[0], strlen($url));
        }, $answer) ?? $answer;

        return preg_replace_callback('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', function (array $match) use ($allowed): string {
            return $this->appears($match[0], $allowed) ? $match[0] : '';
        }, $answer) ?? $answer;
    }

    /** ¿Aparece el texto en los datos como dirección completa, no como el comienzo de otra más larga? */
    private function appears(string $needle, string $allowed): bool
    {
        $pattern = '~(?<![\p{L}\p{N}._%+-])'.preg_quote(mb_strtolower($needle), '~').'(?![\p{L}\p{N}_-]|\.[\p{L}\p{N}])~u';

        return preg_match($pattern, $allowed) === 1;
    }
}
