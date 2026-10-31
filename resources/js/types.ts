export interface SharedProps {
    flash: {
        status: string | null;
        /** Códigos de recuperación recién emitidos; solo llegan una vez. */
        recovery_codes: string[] | null;
    };
    errors: Record<string, string | undefined>;
    [key: string]: unknown;
}
