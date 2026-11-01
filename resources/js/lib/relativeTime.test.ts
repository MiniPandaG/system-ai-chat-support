import { describe, expect, it } from 'vitest';
import { relativeTime } from './relativeTime';

const now = new Date('2026-10-31T12:00:00Z');
const ago = (seconds: number) => new Date(now.getTime() - seconds * 1000).toISOString();

describe('relativeTime', () => {
    it('menos de un minuto es «ahora»', () => {
        expect(relativeTime(ago(5), now)).toBe('ahora');
        expect(relativeTime(ago(59), now)).toBe('ahora');
    });

    it('en minutos, horas y días', () => {
        expect(relativeTime(ago(12 * 60), now)).toMatch(/12 min/);
        expect(relativeTime(ago(3 * 3600), now)).toMatch(/3 h/);
        expect(relativeTime(ago(2 * 86400), now)).toMatch(/2 d|anteayer/);
    });

    it('pasados siete días devuelve nulo para que se muestre la fecha', () => {
        expect(relativeTime(ago(7 * 86400), now)).toBeNull();
        expect(relativeTime(ago(30 * 86400), now)).toBeNull();
    });

    it('una fecha inválida devuelve nulo en vez de «NaN»', () => {
        expect(relativeTime('no es una fecha', now)).toBeNull();
    });
});
