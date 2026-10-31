import { describe, expect, it } from 'vitest';
import { evaluatePassword } from './password';

describe('evaluatePassword', () => {
    it('una contraseña vacía no cumple nada todavía', () => {
        expect(evaluatePassword('')).toEqual({ level: 'empty', missing: ['length', 'uppercase', 'number'] });
    });

    it('indica exactamente qué requisitos faltan', () => {
        expect(evaluatePassword('corta').missing).toEqual(['length', 'uppercase', 'number']);
        expect(evaluatePassword('Corta1').missing).toEqual(['length']);
        expect(evaluatePassword('todominusculas1').missing).toEqual(['uppercase']);
        expect(evaluatePassword('SinNumeroAlguno').missing).toEqual(['number']);
    });

    it('es débil mientras falte cualquier requisito', () => {
        expect(evaluatePassword('Corta1').level).toBe('weak');
    });

    it('es aceptable al cumplir los tres requisitos', () => {
        expect(evaluatePassword('Abcdefghi1')).toEqual({ level: 'fair', missing: [] });
    });

    it('es fuerte con 14 caracteres o más que cumplan los requisitos', () => {
        expect(evaluatePassword('Abcdefghijklm1').level).toBe('strong');
    });

    it('cuenta caracteres y no unidades UTF-16, como hace el servidor', () => {
        // Nueve letras más un emoji son diez caracteres, aunque el emoji ocupe dos unidades.
        expect(evaluatePassword('Abcdefghi😀').missing).toEqual(['number']);
        expect(evaluatePassword('Abcdefgh😀1').missing).toEqual([]);
    });

    it('reconoce mayúsculas y números fuera del alfabeto inglés', () => {
        expect(evaluatePassword('Ñandúcontraseña٣').missing).toEqual([]);
    });
});
