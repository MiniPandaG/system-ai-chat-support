import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { ChangeCopy } from './MetricCard';
import { describeChange, MetricCard } from './MetricCard';

const copy: ChangeCopy = {
    better: 'mejora',
    worse: 'empeora',
    same: 'sin cambio',
    none: 'Sin periodo anterior con qué comparar',
    percent: ':sign:value %',
    points: ':sign:value puntos',
    pp: ':sign:value pp',
};

describe('describeChange', () => {
    it('en una cifra que mejora al subir, subir es mejora y bajar es empeora', () => {
        expect(describeChange({ delta: 12, unit: 'percent', better: 'up' }, copy, false)).toEqual({ text: '+12 % · mejora', tone: 'better' });
        expect(describeChange({ delta: -8, unit: 'percent', better: 'up' }, copy, false)).toEqual({ text: '−8 % · empeora', tone: 'worse' });
    });

    it('en un tiempo, bajar es mejora: el color sigue el significado y no la dirección', () => {
        expect(describeChange({ delta: -50, unit: 'percent', better: 'down' }, copy, false)).toEqual({ text: '−50 % · mejora', tone: 'better' });
        expect(describeChange({ delta: 30, unit: 'percent', better: 'down' }, copy, false)).toEqual({ text: '+30 % · empeora', tone: 'worse' });
    });

    it('los puntos se dicen con coma decimal y, para porcentajes de puntos, como «pp»', () => {
        expect(describeChange({ delta: 0.4, unit: 'points', better: 'up' }, copy, false)?.text).toBe('+0,4 puntos · mejora');
        expect(describeChange({ delta: -5, unit: 'points', better: 'up' }, copy, true)?.text).toBe('−5 pp · empeora');
    });

    it('sin cambio lo dice con palabras, y sin periodo anterior no inventa nada', () => {
        expect(describeChange({ delta: 0, unit: 'percent', better: 'up' }, copy, false)).toEqual({ text: 'sin cambio', tone: 'same' });
        expect(describeChange({ delta: null, unit: 'percent', better: 'up' }, copy, false)).toBeNull();
    });
});

describe('MetricCard', () => {
    it('muestra el título, el número grande y el cambio con signo y palabra', () => {
        render(<MetricCard title="Tickets recibidos" value="42" change={{ delta: 10, unit: 'percent', better: 'up' }} copy={copy} />);

        expect(screen.getByRole('heading', { name: 'Tickets recibidos' })).toBeTruthy();
        expect(screen.getByText('42')).toBeTruthy();
        expect(screen.getByText('+10 % · mejora').className).toContain('text-success');
    });

    it('lo que empeora usa el color de peligro y siempre dice la palabra', () => {
        render(<MetricCard title="Primera respuesta" value="8 min" change={{ delta: 25, unit: 'percent', better: 'down' }} copy={copy} />);

        const text = screen.getByText('+25 % · empeora');
        expect(text.className).toContain('text-danger');
    });

    it('sin periodo anterior dice que no hay con qué comparar', () => {
        render(<MetricCard title="Resueltos" value="0" change={{ delta: null, unit: 'percent', better: 'up' }} copy={copy} />);

        expect(screen.getByText('Sin periodo anterior con qué comparar')).toBeTruthy();
    });

    it('una tarjeta sin comparación (por ejemplo «abiertos ahora») no muestra cambio', () => {
        render(<MetricCard title="Abiertos ahora" value="7" hint="Abiertos y esperando al cliente" copy={copy} />);

        expect(screen.getByText('Abiertos y esperando al cliente')).toBeTruthy();
        expect(screen.queryByText(/Sin periodo anterior/)).toBeNull();
    });
});
