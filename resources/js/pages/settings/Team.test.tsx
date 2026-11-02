import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { SharedProps } from '../../types';
import Team from './Team';

const put = vi.fn();
const post = vi.fn();
let shared: Partial<SharedProps> = {};

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ href, children }: { href: string; children: ReactNode }) => <a href={href}>{children}</a>,
    useForm: () => ({ post: vi.fn(), processing: false, data: { emails: [], role: 'agent' }, errors: {}, setData: vi.fn(), reset: vi.fn(), clearErrors: vi.fn() }),
    usePage: () => ({ props: shared }),
    router: {
        put: (url: string, data: unknown, options?: { onFinish?: () => void }) => {
            put(url, data);
            options?.onFinish?.();
        },
        post: (url: string, data: unknown, options?: { onFinish?: () => void }) => {
            post(url, data);
            options?.onFinish?.();
        },
    },
}));

const copy = {
    title: 'Equipo',
    detail: 'Las personas que tienen acceso a tu empresa.',
    table_label: 'Miembros del equipo',
    name: 'Nombre',
    email: 'Correo',
    role: 'Rol',
    status: 'Estado',
    since: 'Miembro desde',
    actions: 'Acciones',
    you: 'Tú',
    active: 'Activo',
    inactive: 'Desactivado',
    roles: { admin: 'Administrador', agent: 'Agente', observer: 'Observador' },
    role_help: { admin: 'Gestiona el equipo.', agent: 'Atiende tickets.', observer: 'Solo consulta.' },
    change_role: 'Cambiar el rol de :name',
    promote_title: '¿Dar el rol de administrador a :name?',
    promote_detail: 'Un administrador gestiona el equipo.',
    promote_accept: 'Dar rol de administrador',
    promote_accepting: 'Actualizando…',
    cancel: 'Cancelar',
    deactivate: 'Desactivar a :name',
    deactivate_short: 'Desactivar',
    deactivating: 'Desactivando…',
    deactivate_title: '¿Desactivar a :name?',
    deactivate_detail: 'Se cierran todas sus sesiones.',
    deactivate_accept: 'Desactivar',
    activate: 'Reactivar a :name',
    activate_short: 'Reactivar',
    activating: 'Reactivando…',
    empty: 'Aún no hay otros miembros en tu equipo.',
    invite: 'Invitar a alguien',
    invite_title: 'Invitar a tu equipo',
    invite_detail: 'Escribe uno o varios correos.',
    invite_emails: 'Correos',
    invite_emails_hint: 'Sepáralos con coma, espacio o Enter.',
    invite_remove: 'Quitar :email',
    invite_chip_invalid: ':email no es un correo válido.',
    invite_role: 'Rol',
    invite_submit: 'Enviar invitaciones',
    invite_submitting: 'Enviando…',
    invite_cancel: 'Cancelar',
    pending: 'Invitación pendiente',
    expired: 'Caducada',
    expires: 'Caduca el :when',
    pending_name: 'Sin aceptar',
    resend: 'Reenviar',
    resend_label: 'Reenviar la invitación a :email',
    revoke: 'Cancelar invitación',
    revoke_label: 'Cancelar la invitación a :email',
    seats: 'Puestos de agente: :used de :max (plan :plan)',
    seats_unlimited: 'Puestos de agente: :used (plan :plan, sin límite)',
    seats_full: 'Tu plan :plan permite :max puestos de agente y ya los usas.',
    seats_over: 'Tu empresa usa :used puestos y su plan :plan permite :max.',
};

const seats = { plan: 'Inicial', used: 1, max: 2, remaining: 1, over: false };

const pendingInvitations = [
    { id: 11, email: 'nueva@empresa-ejemplo.com', role: 'agent', expired: false, expires_label: '3 de nov., 10:00 am' },
    { id: 12, email: 'vieja@empresa-ejemplo.com', role: 'observer', expired: true, expires_label: '1 de nov., 10:00 am' },
];

const members = [
    { id: 1, name: 'Ana Torres', email: 'ana@empresa-ejemplo.com', role: 'admin', active: true, is_self: true, since: '2026-10-01T10:00:00-05:00', since_label: '1 de oct. de 2026' },
    { id: 2, name: 'Luis Pérez', email: 'luis@empresa-ejemplo.com', role: 'agent', active: true, is_self: false, since: '2026-10-05T10:00:00-05:00', since_label: '5 de oct. de 2026' },
    { id: 3, name: 'Marta Gil', email: 'marta@empresa-ejemplo.com', role: 'observer', active: false, is_self: false, since: '2026-10-07T10:00:00-05:00', since_label: '7 de oct. de 2026' },
];

function dialogButton(name: string): HTMLElement {
    const dialog = document.querySelector<HTMLElement>('dialog[role="alertdialog"]');
    if (dialog === null) throw new Error('No hay diálogo.');

    return within(dialog).getByRole('button', { name, hidden: true });
}

describe('Team', () => {
    beforeEach(() => {
        put.mockClear();
        post.mockClear();
        shared = {
            errors: {},
            flash: { status: null, recovery_codes: null },
            panel: {
                nav_label: 'Navegación principal',
                account: 'Mi cuenta',
                security: 'Seguridad',
                inbox: 'Bandeja',
                inbox_unassigned: ':count sin asignar',
                live_on: 'En vivo',
                live_connecting: 'Conectando…',
                live_off: 'Sin conexión en vivo',
                team: 'Equipo',
                branding: 'Marca',
                widget: 'Chat web',
                plan: 'Plan',
                platform: 'Plataforma',
                open_menu: 'Abrir menú',
                close_menu: 'Cerrar menú',
                logout: 'Cerrar sesión',
                logging_out: 'Cerrando sesión…',
                roles: { admin: 'Administrador' },
            },
            auth: { user: { id: 1, name: 'Ana Torres', email: 'ana@empresa-ejemplo.com', role: 'admin' }, tenant: { id: 1, name: 'Soporte Ejemplo SAS', logo_url: null, theme: null }, can: { tickets: true, team: true, branding: true, plan: true, widget: true } },
        };
    });

    it('es una tabla real con encabezados de columna y una fila por miembro', () => {
        render(<Team copy={copy} members={members} invitations={[]} seats={seats} />);

        const table = screen.getByRole('table', { name: 'Miembros del equipo' });

        expect(within(table).getAllByRole('columnheader', { hidden: true })).toHaveLength(6);
        expect(within(table).getAllByRole('row', { hidden: true })).toHaveLength(4);
    });

    it('la fila propia no ofrece cambiar el rol ni desactivar', () => {
        render(<Team copy={copy} members={members} invitations={[]} seats={seats} />);

        expect(screen.getByText('Tú')).toBeTruthy();
        expect(screen.queryByLabelText('Cambiar el rol de Ana Torres')).toBeNull();
        expect(screen.queryByRole('button', { name: 'Desactivar a Ana Torres' })).toBeNull();
    });

    it('cambiar a un rol sin privilegios se aplica de inmediato', async () => {
        const user = userEvent.setup();
        render(<Team copy={copy} members={members} invitations={[]} seats={seats} />);

        await user.selectOptions(screen.getByLabelText('Cambiar el rol de Luis Pérez'), 'observer');

        expect(put).toHaveBeenCalledWith('/settings/team/members/2/role', { role: 'observer' });
    });

    it('dar el rol de administrador pide confirmación y solo actúa al confirmar', async () => {
        const user = userEvent.setup();
        render(<Team copy={copy} members={members} invitations={[]} seats={seats} />);

        await user.selectOptions(screen.getByLabelText('Cambiar el rol de Luis Pérez'), 'admin');
        expect(put).not.toHaveBeenCalled();
        expect(document.querySelector('dialog[role="alertdialog"]')?.hasAttribute('open')).toBe(true);

        await user.click(dialogButton('Cancelar'));
        expect(put).not.toHaveBeenCalled();

        await user.selectOptions(screen.getByLabelText('Cambiar el rol de Luis Pérez'), 'admin');
        await user.click(dialogButton('Dar rol de administrador'));
        expect(put).toHaveBeenCalledWith('/settings/team/members/2/role', { role: 'admin' });
    });

    it('desactivar pide confirmación y envía la petición solo al confirmar', async () => {
        const user = userEvent.setup();
        render(<Team copy={copy} members={members} invitations={[]} seats={seats} />);

        await user.click(screen.getByRole('button', { name: 'Desactivar a Luis Pérez' }));
        expect(post).not.toHaveBeenCalled();

        await user.click(dialogButton('Desactivar'));
        expect(post).toHaveBeenCalledWith('/settings/team/members/2/deactivate', {});
    });

    it('un miembro desactivado se marca como tal, no se le cambia el rol y se reactiva sin confirmar', async () => {
        const user = userEvent.setup();
        render(<Team copy={copy} members={members} invitations={[]} seats={seats} />);

        expect(screen.getByText('Desactivado')).toBeTruthy();
        expect(screen.getByLabelText<HTMLSelectElement>('Cambiar el rol de Marta Gil').disabled).toBe(true);

        await user.click(screen.getByRole('button', { name: 'Reactivar a Marta Gil' }));
        expect(post).toHaveBeenCalledWith('/settings/team/members/3/activate', {});
    });

    it('muestra el aviso de éxito y los errores del servidor', () => {
        shared = { ...shared, flash: { status: 'Actualizamos el rol.', recovery_codes: null }, errors: { role: 'El rol no es válido.' } };
        render(<Team copy={copy} members={members} invitations={[]} seats={seats} />);

        expect(screen.getByText('Actualizamos el rol.')).toBeTruthy();
        expect(screen.getByText('El rol no es válido.')).toBeTruthy();
    });

    it('si el equipo solo tiene a su administrador, lo dice', () => {
        render(<Team copy={copy} members={members.slice(0, 1)} invitations={[]} seats={seats} />);

        expect(screen.getByText('Aún no hay otros miembros en tu equipo.')).toBeTruthy();
    });

    it('lista las invitaciones pendientes y caducadas con sus acciones', async () => {
        const user = userEvent.setup();
        render(<Team copy={copy} members={members} invitations={pendingInvitations} seats={seats} />);

        expect(screen.getByText('Invitación pendiente')).toBeTruthy();
        expect(screen.getByText('Caducada')).toBeTruthy();
        expect(screen.getAllByText('Sin aceptar')).toHaveLength(2);

        await user.click(screen.getByRole('button', { name: 'Reenviar la invitación a nueva@empresa-ejemplo.com' }));
        expect(post).toHaveBeenCalledWith('/settings/team/invitations/11/resend', {});

        await user.click(screen.getByRole('button', { name: 'Cancelar la invitación a vieja@empresa-ejemplo.com' }));
        expect(post).toHaveBeenCalledWith('/settings/team/invitations/12/revoke', {});
    });

    it('el botón de invitar abre el formulario de invitación', async () => {
        const user = userEvent.setup();
        render(<Team copy={copy} members={members} invitations={[]} seats={seats} />);

        await user.click(screen.getByRole('button', { name: 'Invitar a alguien' }));

        const dialog = document.querySelector('dialog[aria-labelledby="invite-title"]');
        expect(dialog?.hasAttribute('open')).toBe(true);
        expect(within(dialog as HTMLElement).getByLabelText('Correos')).toBeTruthy();
    });

    it('muestra los puestos usados y el plan', () => {
        render(<Team copy={copy} members={members} invitations={[]} seats={seats} />);

        expect(screen.getByText('Puestos de agente: 1 de 2 (plan Inicial)')).toBeTruthy();
        expect(screen.queryByText(/ya los usas/)).toBeNull();
    });

    it('avisa cuando no quedan puestos, sin impedir invitar observadores', () => {
        render(<Team copy={copy} members={members} invitations={[]} seats={{ ...seats, used: 2, remaining: 0 }} />);

        expect(screen.getByText(/Tu plan Inicial permite 2 puestos de agente y ya los usas/)).toBeTruthy();
        expect(screen.getByRole('button', { name: 'Invitar a alguien' }).hasAttribute('disabled')).toBe(false);
    });

    it('avisa cuando la empresa está por encima del tope tras bajar de plan', () => {
        render(<Team copy={copy} members={members} invitations={[]} seats={{ ...seats, used: 5, max: 2, remaining: 0, over: true }} />);

        expect(screen.getByText('Tu empresa usa 5 puestos y su plan Inicial permite 2.')).toBeTruthy();
    });

    it('sin límite lo dice y no muestra avisos', () => {
        render(<Team copy={copy} members={members} invitations={[]} seats={{ ...seats, max: null, remaining: null }} />);

        expect(screen.getByText('Puestos de agente: 1 (plan Inicial, sin límite)')).toBeTruthy();
        expect(screen.queryByText(/Tu empresa usa/)).toBeNull();
    });
});
