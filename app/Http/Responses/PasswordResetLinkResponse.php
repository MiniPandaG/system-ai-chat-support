<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Enums\AuditEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Una sola respuesta para «enlace enviado» y «cuenta inexistente», para no revelar qué correos tienen cuenta.
 */
class PasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    // El estado del broker solo se guarda en la auditoría; la respuesta al usuario es la misma en todos los casos.
    public function __construct(private readonly string $status) {}

    public function toResponse($request): JsonResponse|RedirectResponse
    {
        $email = $request->input('email');

        if (is_string($email) && $email !== '') {
            $userId = User::query()->where('email', mb_strtolower($email))->value('id');

            app(AuditLogger::class)->record(
                AuditEvent::PasswordResetRequested,
                is_int($userId) ? $userId : null,
                ['email_fingerprint' => AuditLogger::fingerprint($email), 'result' => $this->status],
            );
        }

        $message = __('passwords.sent');

        return $request->wantsJson()
            ? new JsonResponse(['message' => $message], 200)
            : back()->with('status', $message);
    }
}
