export interface PanelCopy {
    nav_label: string;
    account: string;
    security: string;
    team: string;
    platform: string;
    open_menu: string;
    close_menu: string;
    logout: string;
    logging_out: string;
    roles: Record<string, string>;
}

export interface AuthenticatedUser {
    user: { name: string; email: string; role: string | null };
    tenant: { name: string } | null;
    can: { team: boolean };
}

export interface SharedProps {
    /** Quién tiene la sesión y de qué empresa; null para visitantes. */
    auth: AuthenticatedUser | null;
    panel: PanelCopy;
    flash: {
        status: string | null;
        /** Códigos de recuperación recién emitidos; solo llegan una vez. */
        recovery_codes: string[] | null;
    };
    errors: Record<string, string | undefined>;
    [key: string]: unknown;
}
