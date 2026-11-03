<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Enums\AuditEvent;
use App\Models\KbContext;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * El contexto de la empresa (RF-18): qué vende, sus políticas y el tono que quiere. Es texto plano y la IA lo recibe
 * siempre completo, así que no se busca ni se indexa. Es contenido no confiable como el resto de lo que escribe la empresa:
 * se limpia al guardarlo y quien lo lea para armar un mensaje al modelo debe tratarlo como datos, nunca como órdenes.
 */
class CompanyContext
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** El texto guardado de la empresa en contexto, o vacío si todavía no escribió ninguno. */
    public function text(): string
    {
        $context = KbContext::query()->first();

        return $context === null ? '' : $context->body;
    }

    public function save(User $actor, string $text): string
    {
        $clean = trim(TextCleaner::clean($text));
        $context = KbContext::query()->first() ?? new KbContext;
        $context->forceFill(['body' => $clean, 'updated_by' => $actor->id])->save();

        $this->audit->record(AuditEvent::KnowledgeContextChanged, $actor->id, ['context_id' => $context->id]);

        return $clean;
    }
}
