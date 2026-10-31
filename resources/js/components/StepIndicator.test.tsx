import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { StepIndicator } from './StepIndicator';

describe('StepIndicator', () => {
    it('es una lista con nombre y marca solo el paso actual con aria-current', () => {
        render(<StepIndicator label="Pasos del registro" steps={['Cuenta', 'Empresa']} current={0} />);

        const list = screen.getByRole('list', { name: 'Pasos del registro' });
        const items = screen.getAllByRole('listitem');

        expect(list).toBeTruthy();
        expect(items).toHaveLength(2);
        expect(items[0]?.getAttribute('aria-current')).toBe('step');
        expect(items[1]?.getAttribute('aria-current')).toBeNull();
    });

    it('un paso completado deja de ser el actual y muestra una marca en vez de su número', () => {
        render(<StepIndicator label="Pasos" steps={['Cuenta', 'Empresa']} current={1} />);

        const items = screen.getAllByRole('listitem');

        expect(items[0]?.getAttribute('aria-current')).toBeNull();
        expect(items[0]?.querySelector('svg')).not.toBeNull();
        expect(items[0]?.textContent).toBe('Cuenta');
        expect(items[1]?.getAttribute('aria-current')).toBe('step');
        expect(items[1]?.textContent).toContain('2');
    });

    it('los pasos futuros conservan su número', () => {
        render(<StepIndicator label="Pasos" steps={['A', 'B', 'C']} current={0} />);

        expect(screen.getAllByRole('listitem')[2]?.textContent).toContain('3');
    });
});
