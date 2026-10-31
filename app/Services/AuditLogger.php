<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use InvalidArgumentException;

class AuditLogger
{
    private const FORBIDDEN_CONTEXT_KEYS = '/password|secret|token|code|key|email$/i';

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, scalar|null>  $context  Nunca contraseñas, tokens, códigos ni correos en claro.
     */
    public function record(AuditEvent $event, ?int $userId = null, array $context = []): AuditLog
    {
        foreach (array_keys($context) as $key) {
            if (preg_match(self::FORBIDDEN_CONTEXT_KEYS, $key) === 1) {
                throw new InvalidArgumentException("El campo {$key} no puede guardarse en la auditoría.");
            }
        }

        return AuditLog::create([
            'event' => $event->value,
            'user_id' => $userId,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 255) ?: null,
            'context' => $context === [] ? null : $context,
        ]);
    }

    /**
     * Huella no reversible de un correo, para reconocer repeticiones sin guardar el dato personal.
     */
    public static function fingerprint(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), config()->string('app.key'));
    }
}
