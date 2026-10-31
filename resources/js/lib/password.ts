export type PasswordRequirement = 'length' | 'uppercase' | 'number';
export type StrengthLevel = 'empty' | 'weak' | 'fair' | 'strong';

export interface PasswordCheck {
    level: StrengthLevel;
    /** Requisitos que todavía no cumple, en el orden en que se muestran. */
    missing: PasswordRequirement[];
}

const minimumLength = 10;
const strongLength = 14;

/**
 * Evalúa la contraseña con las mismas reglas que valida el servidor (10 caracteres, una mayúscula, un número).
 * Es solo una ayuda al escribir: la validación que cuenta es la del servidor.
 */
export function evaluatePassword(password: string): PasswordCheck {
    if (password === '') {
        return { level: 'empty', missing: ['length', 'uppercase', 'number'] };
    }

    const missing: PasswordRequirement[] = [];
    if (Array.from(password).length < minimumLength) missing.push('length');
    if (!/\p{Lu}/u.test(password)) missing.push('uppercase');
    if (!/\p{N}/u.test(password)) missing.push('number');

    if (missing.length > 0) {
        return { level: 'weak', missing };
    }

    return { level: Array.from(password).length >= strongLength ? 'strong' : 'fair', missing };
}
