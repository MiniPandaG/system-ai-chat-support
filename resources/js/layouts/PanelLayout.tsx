import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ComponentType, ReactNode, SVGProps } from 'react';
import logo from '../../images/logo.png';
import { Button } from '../components/Button';
import { ConnectionIndicator } from '../components/ConnectionIndicator';
import { ChatIcon, GaugeIcon, InboxIcon, MenuIcon, PaletteIcon, ShieldIcon, UserIcon, UsersIcon } from '../components/icons';
import { disconnect } from '../realtime/client';
import type { SharedProps } from '../types';

export type PanelSection = 'inbox' | 'account' | 'team' | 'branding' | 'widget' | 'plan' | 'security';

interface PanelLayoutProps {
    title: string;
    section: PanelSection;
    /** Para pantallas con tablas: usa más ancho que un formulario. */
    wide?: boolean;
    /** Para pantallas que reparten todo el alto en paneles con su propio scroll, como la bandeja. */
    bare?: boolean;
    children: ReactNode;
}

interface NavItem {
    key: PanelSection;
    href: string;
    Icon: ComponentType<Omit<SVGProps<SVGSVGElement>, 'children'>>;
}

const navItems: NavItem[] = [
    { key: 'inbox', href: '/tickets', Icon: InboxIcon },
    { key: 'account', href: '/account', Icon: UserIcon },
    { key: 'team', href: '/settings/team', Icon: UsersIcon },
    { key: 'branding', href: '/settings/branding', Icon: PaletteIcon },
    { key: 'widget', href: '/settings/widget', Icon: ChatIcon },
    { key: 'plan', href: '/settings/plan', Icon: GaugeIcon },
    { key: 'security', href: '/settings/security', Icon: ShieldIcon },
];

/**
 * Estructura del panel (design/pantallas/estructura-panel.md): navegación lateral de 240 px, solo iconos entre
 * 768 y 1023 px, y menú de hoja inferior desde 360 px. Solo lista las secciones que ya existen.
 */
export function PanelLayout({ title, section, wide = false, bare = false, children }: PanelLayoutProps) {
    const { props } = usePage<SharedProps>();
    const { panel, auth } = props;
    const logout = useForm({});
    const [menuOpen, setMenuOpen] = useState(false);
    const sheetRef = useRef<HTMLDialogElement>(null);
    const theme = auth?.tenant?.theme ?? null;

    // La paleta de la empresa llega ya validada (solo el primario y su texto). Se aplica con setProperty, que la CSP
    // permite, y al volver a la paleta por defecto se quitan las variables para recuperar la de fábrica.
    useEffect(() => {
        const root = document.documentElement;
        // La etiqueta de estilo de la primera carga evita el parpadeo; desde aquí React se encarga y, si la empresa
        // vuelve a la paleta por defecto, no queda un color viejo atrapado en ella.
        document.getElementById('brand-theme')?.remove();
        const names = theme === null ? [] : Object.keys(theme);

        for (const name of names) root.style.setProperty(name, theme?.[name] ?? '');

        return () => {
            for (const name of names) root.style.removeProperty(name);
        };
    }, [theme]);

    useEffect(() => {
        const sheet = sheetRef.current;
        if (sheet === null) return;

        if (menuOpen && !sheet.open) sheet.showModal();
        if (!menuOpen && sheet.open) sheet.close();
    }, [menuOpen]);

    const labels: Record<PanelSection, string> = { inbox: panel.inbox, account: panel.account, team: panel.team, branding: panel.branding, widget: panel.widget, plan: panel.plan, security: panel.security };
    // El servidor decide qué secciones ve cada rol; aquí solo se ocultan las que no puede abrir.
    const visibleItems = navItems.filter(
        (item) =>
            (item.key !== 'inbox' || auth?.can.tickets === true) &&
            (item.key !== 'team' || auth?.can.team === true) &&
            (item.key !== 'branding' || auth?.can.branding === true) &&
            (item.key !== 'widget' || auth?.can.widget === true) &&
            (item.key !== 'plan' || auth?.can.plan === true),
    );
    const unassigned = auth?.inbox?.unassigned ?? 0;
    const companyName = auth?.tenant?.name ?? panel.platform;
    const roleLabel = auth?.user.role == null ? null : (panel.roles[auth.user.role] ?? null);

    const links = (onNavigate?: () => void) =>
        visibleItems.map(({ key, href, Icon }) => {
            const active = key === section;

            return (
                <Link
                    key={key}
                    href={href}
                    title={labels[key]}
                    aria-current={active ? 'page' : undefined}
                    {...(onNavigate === undefined ? {} : { onClick: onNavigate })}
                    className={`flex h-10 items-center gap-3 border-l-2 px-3 text-base font-medium transition duration-fast ease-standard ${
                        active
                            ? 'border-primary bg-primary-surface text-text'
                            : 'border-transparent text-text-secondary hover:bg-surface-hover hover:text-text'
                    }`}
                >
                    <Icon className="size-5 shrink-0" />
                    <span className="md:sr-only lg:not-sr-only">{labels[key]}</span>
                    {key === 'inbox' && unassigned > 0 && (
                        <>
                            <span aria-hidden="true" className="ml-auto hidden min-w-6 items-center justify-center rounded-full bg-primary px-2 text-xs font-semibold text-primary-foreground lg:inline-flex max-md:inline-flex">
                                {unassigned}
                            </span>
                            <span className="sr-only">{panel.inbox_unassigned.replace(':count', String(unassigned))}</span>
                        </>
                    )}
                </Link>
            );
        });

    return (
        <>
            <Head title={title} />
            <div className={`flex bg-background text-text ${bare ? 'h-dvh' : 'min-h-screen'}`}>
                <aside className="hidden shrink-0 flex-col border-r border-border bg-surface md:flex md:w-16 lg:w-60">
                    <div className="flex h-14 items-center gap-3 border-b border-border px-4">
                        <img src={auth?.tenant?.logo_url ?? logo} alt="" className="size-8 shrink-0 rounded-sm object-contain" />
                        <span className="truncate font-display font-bold md:sr-only lg:not-sr-only">{companyName}</span>
                    </div>
                    <nav aria-label={panel.nav_label} className="flex flex-col gap-1 py-2">
                        {links()}
                    </nav>
                </aside>

                <div className="flex min-h-0 min-w-0 flex-1 flex-col">
                    <header className="flex h-14 shrink-0 items-center gap-3 border-b border-border bg-surface px-4">
                        <button
                            type="button"
                            aria-label={panel.open_menu}
                            className="-ml-2 inline-flex size-11 items-center justify-center rounded-md text-text-secondary hover:bg-surface-hover hover:text-text md:hidden"
                            onClick={() => {
                                setMenuOpen(true);
                            }}
                        >
                            <MenuIcon className="size-5" />
                        </button>
                        <h1 className="truncate font-display text-lg font-semibold">{title}</h1>
                        <div className="ml-auto flex items-center gap-3">
                            {auth?.can.tickets === true && <ConnectionIndicator copy={panel} />}
                            {auth !== null && (
                                <p className="hidden text-sm text-text-secondary sm:block">
                                    {auth.user.name}
                                    {roleLabel !== null && <span className="text-text-tertiary"> · {roleLabel}</span>}
                                </p>
                            )}
                            <Button
                                variant="text"
                                loading={logout.processing}
                                loadingLabel={panel.logging_out}
                                onClick={() => {
                                    // La conexión en vivo es de esta sesión: se cierra para que nadie la herede.
                                    logout.post('/logout', { onFinish: disconnect });
                                }}
                            >
                                {panel.logout}
                            </Button>
                        </div>
                    </header>

                    {bare ? (
                        <main className="min-h-0 flex-1 overflow-hidden">{children}</main>
                    ) : (
                        <main className="flex-1 overflow-y-auto">
                            <div className={`mx-auto flex w-full flex-col gap-8 px-4 py-8 ${wide ? 'max-w-[64rem]' : 'max-w-[40rem]'}`}>{children}</div>
                        </main>
                    )}
                </div>
            </div>

            <dialog
                ref={sheetRef}
                aria-label={panel.nav_label}
                className="m-0 mt-auto w-full max-w-none rounded-t-lg bg-surface-raised p-4 text-text shadow-lg backdrop:bg-[var(--color-overlay-backdrop)] md:hidden"
                onClose={() => {
                    setMenuOpen(false);
                }}
            >
                <nav aria-label={panel.nav_label} className="flex flex-col gap-1">
                    {links(() => {
                        setMenuOpen(false);
                    })}
                </nav>
                <Button
                    variant="text"
                    fullWidth
                    className="mt-3"
                    onClick={() => {
                        setMenuOpen(false);
                    }}
                >
                    {panel.close_menu}
                </Button>
            </dialog>
        </>
    );
}
