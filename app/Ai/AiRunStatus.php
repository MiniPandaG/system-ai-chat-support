<?php

declare(strict_types=1);

namespace App\Ai;

/** En qué quedó una ejecución de la IA. Lista cerrada. */
enum AiRunStatus: string
{
    /** Creada, todavía sin resultado. */
    case Pending = 'pending';

    /** El modelo dio una respuesta válida; falta decidir qué se hace con ella. */
    case Answered = 'answered';

    /** La IA no pudo o no debía responder. */
    case NoAnswer = 'no_answer';

    /** El modelo contestó algo que no se pudo usar. */
    case InvalidOutput = 'invalid_output';

    /** No se pudo llamar al modelo. */
    case Failed = 'failed';

    /** Enviada al cliente. */
    case Sent = 'sent';

    /** Borrador esperando a una persona (modo sombra o poca confianza). */
    case Drafted = 'drafted';

    /** Un agente aprobó el borrador y se envió. */
    case Approved = 'approved';

    /** Un agente descartó el borrador. */
    case Discarded = 'discarded';

    /** Pasó a una persona. */
    case Escalated = 'escalated';

    /** No se corrió (IA apagada, fuera de horario, tope alcanzado…). */
    case Skipped = 'skipped';
}
