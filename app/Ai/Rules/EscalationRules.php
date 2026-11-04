<?php

declare(strict_types=1);

namespace App\Ai\Rules;

use App\Ai\AiRunStatus;
use App\Models\AiRun;
use App\Models\AiSettings;
use App\Models\Ticket;
use Normalizer;

/**
 * Las reglas que pasan un caso a una persona (RIA-03, RIA-05). Las aplica el código sobre el texto del cliente y el estado
 * del ticket, antes de llamar a ningún modelo: no dependen de que el modelo las entienda ni de que las obedezca.
 */
class EscalationRules
{
    /** Frases con las que un cliente pide una persona, en español y en inglés, ya sin acentos ni mayúsculas. */
    private const HUMAN_PATTERNS = [
        '/\b(?:hablar|comunicar|comunicarme|pasar|pasame|pasarme|contactar|conectar|conectarme|atiende|atienden|atienda|atiendan|atender|atenderme|chatear)\b.{0,40}\b(?:persona|humano|humana|agente|asesor|asesora|operador|operadora|representante|ejecutivo|ejecutiva|supervisor|supervisora|alguien)\b/u',
        '/\b(?:quiero|necesito|prefiero|pido|exijo|requiero)\b.{0,20}\b(?:un|una)?\s*(?:persona|humano|humana|agente|asesor|asesora|operador|operadora|representante)\b/u',
        '/\b(?:persona|humano|humana|agente|asesor|asesora|operador|operadora)\s+(?:real|de verdad)\b/u',
        '/\b(?:no|ya no)\s+quiero\s+(?:hablar\s+)?con\s+(?:un\s+|una\s+|el\s+|la\s+)?(?:bot|robot|ia|maquina|asistente virtual|inteligencia artificial)\b/u',
        '/\b(?:talk|speak|chat|connect me)\b.{0,20}\b(?:to|with)?\s*(?:a|an|the)?\s*(?:human|real person|person|live agent|agent|representative|someone)\b/u',
        '/\b(?:human|live agent|real person)\s+(?:please|now)\b/u',
    ];

    public function humanRequested(string $text): bool
    {
        $normalized = self::normalize($text);

        foreach (self::HUMAN_PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /** ¿El mensaje menciona alguno de los temas que la empresa reservó para personas? Palabra o frase completa. */
    public function topicMatched(AiSettings $settings, string $text): bool
    {
        $normalized = self::normalize($text);

        foreach ($settings->escalate_topics ?? [] as $topic) {
            $needle = self::normalize($topic);

            if ($needle !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?![\p{L}\p{N}])/u', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /** ¿La IA ya envió en este ticket todos los mensajes que la empresa le permite? */
    public function messageLimitReached(Ticket $ticket, AiSettings $settings): bool
    {
        $sent = AiRun::query()->where('ticket_id', $ticket->id)->whereIn('status', [AiRunStatus::Sent->value, AiRunStatus::Approved->value])->count();

        return $sent >= $settings->max_messages;
    }

    /** Minúsculas, sin acentos y con los espacios reducidos: para comparar sin que importe cómo se escribió. */
    public static function normalize(string $text): string
    {
        $decomposed = Normalizer::normalize(mb_strtolower($text), Normalizer::FORM_D);
        $plain = preg_replace('/\p{Mn}+/u', '', is_string($decomposed) ? $decomposed : mb_strtolower($text)) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $plain) ?? '');
    }
}
