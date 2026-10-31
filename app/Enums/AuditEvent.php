<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditEvent: string
{
    case LoginSucceeded = 'login.succeeded';
    case LoginFailed = 'login.failed';
    case LoginLockedOut = 'login.locked_out';
    case Logout = 'logout';
    case PlatformAdminCreated = 'platform_admin.created';
    case PasswordResetRequested = 'password_reset.requested';
    case PasswordResetCompleted = 'password_reset.completed';
    case EmailVerified = 'email.verified';
    case TwoFactorConfirmed = 'two_factor.confirmed';
    case TwoFactorDisabled = 'two_factor.disabled';
    case TwoFactorFailed = 'two_factor.failed';
    case RecoveryCodesGenerated = 'two_factor.recovery_codes_generated';
    case RecoveryCodeUsed = 'two_factor.recovery_code_used';
    case PasswordChanged = 'password.changed';
    case SessionRevoked = 'session.revoked';
    case SessionsRevoked = 'sessions.revoked';
}
