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
    case TenantCreated = 'tenant.created';
    case MemberRoleChanged = 'member.role_changed';
    case MemberDeactivated = 'member.deactivated';
    case MemberActivated = 'member.activated';
    case InvitationCreated = 'invitation.created';
    case InvitationResent = 'invitation.resent';
    case InvitationRevoked = 'invitation.revoked';
    case InvitationAccepted = 'invitation.accepted';
    case FileUploaded = 'file.uploaded';
    case FileDeleted = 'file.deleted';
    case BrandingNameChanged = 'branding.name_changed';
    case BrandingLogoChanged = 'branding.logo_changed';
    case BrandingLogoRemoved = 'branding.logo_removed';
    case BrandingPaletteChanged = 'branding.palette_changed';
    case BrandingPaletteReset = 'branding.palette_reset';
    case PlanAssigned = 'plan.assigned';
    case TicketCreated = 'ticket.created';
    case TicketAssigned = 'ticket.assigned';
    case TicketAttachmentInfected = 'ticket.attachment_infected';
    case WidgetOriginAdded = 'widget.origin_added';
    case WidgetOriginRemoved = 'widget.origin_removed';
    case WidgetAppearanceChanged = 'widget.appearance_changed';
    case ApiKeyCreated = 'api_key.created';
    case ApiKeyRotated = 'api_key.rotated';
    case ApiKeyRevoked = 'api_key.revoked';
}
