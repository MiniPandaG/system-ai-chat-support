import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { KnowledgeTabs } from './KnowledgeTabs';

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children, ...rest }: { href: string; children: ReactNode; 'aria-current'?: 'page' }) => (
        <a href={href} {...rest}>
            {children}
        </a>
    ),
}));

describe('KnowledgeTabs', () => {
    it('lista las dos secciones como enlaces y marca la actual', () => {
        render(<KnowledgeTabs current="documents" label="Secciones" articles="Artículos" documents="Documentos" context="Contexto" />);

        expect(screen.getByRole('navigation', { name: 'Secciones' })).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Artículos' }).getAttribute('href')).toBe('/knowledge');
        expect(screen.getByRole('link', { name: 'Documentos' }).getAttribute('href')).toBe('/knowledge/documents');
        expect(screen.getByRole('link', { name: 'Documentos' }).getAttribute('aria-current')).toBe('page');
        expect(screen.getByRole('link', { name: 'Artículos' }).getAttribute('aria-current')).toBeNull();
    });

    it('incluye el contexto de la empresa y lo marca cuando es la sección actual', () => {
        render(<KnowledgeTabs current="context" label="Secciones" articles="Artículos" documents="Documentos" context="Contexto" />);

        expect(screen.getByRole('link', { name: 'Contexto' }).getAttribute('href')).toBe('/knowledge/context');
        expect(screen.getByRole('link', { name: 'Contexto' }).getAttribute('aria-current')).toBe('page');
        expect(screen.getByRole('link', { name: 'Documentos' }).getAttribute('aria-current')).toBeNull();
    });

    it('marca los artículos cuando son la sección actual', () => {
        render(<KnowledgeTabs current="articles" label="Secciones" articles="Artículos" documents="Documentos" context="Contexto" />);

        expect(screen.getByRole('link', { name: 'Artículos' }).getAttribute('aria-current')).toBe('page');
    });
});
