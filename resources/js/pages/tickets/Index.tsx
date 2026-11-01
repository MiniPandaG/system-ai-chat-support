import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { EmptyState } from '../../components/EmptyState';
import { InboxToolbar } from '../../components/InboxToolbar';
import { ArrowLeftIcon, ChatIcon, InboxIcon, PlusIcon } from '../../components/icons';
import { NewTicketDialog } from '../../components/NewTicketDialog';
import { TicketDetail } from '../../components/TicketDetail';
import { TicketList, TicketListSkeleton } from '../../components/TicketList';
import { inboxParams, inboxQuery, isFiltered } from '../../lib/inbox';
import { PanelLayout } from '../../layouts/PanelLayout';
import type { Assignee, InboxCounts, InboxFilters, SavedView, TagData, TicketDetailData, TicketRowData, TicketsCopy } from '../../ticketTypes';
import type { SharedProps } from '../../types';

interface IndexProps {
    copy: TicketsCopy;
    tickets: TicketRowData[];
    next_cursor: string | null;
    total: number;
    filters: InboxFilters;
    counts: InboxCounts;
    views: SavedView[];
    filter_tags: TagData[];
    selected: TicketDetailData | null;
    assignees: Assignee[];
    tag_options: TagData[];
    can: { create: boolean; update: boolean; save_view: boolean };
}

/** Segundos que dura el aviso de éxito antes de irse solo (design/componentes/aviso.md). */
const NOTICE_SECONDS = 4;

/**
 * La bandeja (design/patrones/bandeja.md): filtros, búsqueda y la lista a la izquierda y el ticket abierto a la
 * derecha. Desde 360 px es una sola columna: la lista, y al abrir un ticket, el ticket con un enlace para volver.
 */
export default function Index({ copy, tickets, next_cursor, total, filters, counts, views, filter_tags, selected, assignees, tag_options, can }: IndexProps) {
    const { props, url } = usePage<SharedProps>();
    const [creating, setCreating] = useState(false);
    const [loadingList, setLoadingList] = useState(false);
    const [dismissedFlash, setDismissedFlash] = useState<SharedProps['flash'] | null>(null);
    const notice = props.flash.status !== null && dismissedFlash !== props.flash ? props.flash.status : null;
    // Los filtros se aplican sobre la misma pantalla: si hay un ticket abierto, sigue abierto mientras cambia la lista.
    const basePath = url.split('?')[0] ?? '/tickets';
    const query = inboxQuery(filters);
    const filtered = isFiltered(filters);
    const text = copy.inbox;

    useEffect(() => {
        if (props.flash.status === null) return;

        const flash = props.flash;
        const timer = window.setTimeout(() => {
            setDismissedFlash(flash);
        }, NOTICE_SECONDS * 1000);

        return () => {
            window.clearTimeout(timer);
        };
    }, [props.flash]);

    // Cambiar un filtro muestra el esqueleto de inmediato: nunca la lista anterior congelada mientras llega la nueva.
    const visit = (next: InboxFilters) => {
        setLoadingList(true);
        router.get(basePath, inboxParams(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onFinish: () => {
                setLoadingList(false);
            },
        });
    };

    const countText = total === 0 ? text.count_none : total === 1 ? text.count_one : text.count_many.replace(':count', total.toLocaleString('es'));

    return (
        <PanelLayout title={copy.title} section="inbox" bare>
            <div className="flex h-full min-h-0">
                <section
                    aria-label={copy.list_label}
                    className={`min-h-0 w-full flex-col border-r border-border bg-surface lg:w-[26rem] lg:shrink-0 ${selected === null ? 'flex' : 'hidden lg:flex'}`}
                >
                    <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
                        <p role="status" className="text-sm text-text-secondary">
                            <span className="font-medium text-text">{countText}</span>
                        </p>
                        {can.create && (
                            <Button
                                onClick={() => {
                                    setCreating(true);
                                }}
                            >
                                <span className="flex items-center gap-2">
                                    <PlusIcon className="size-4" />
                                    {copy.new_ticket}
                                </span>
                            </Button>
                        )}
                    </div>
                    {notice !== null && (
                        <div className="px-4 pt-3">
                            <Alert variant="success">{notice}</Alert>
                        </div>
                    )}
                    <div className="min-h-0 flex-1 overflow-y-auto">
                        <InboxToolbar
                            copy={copy}
                            filters={filters}
                            counts={counts}
                            views={views}
                            assignees={assignees}
                            tags={filter_tags}
                            canSaveView={can.save_view}
                            basePath={basePath}
                            onVisit={visit}
                        />
                        {loadingList ? (
                            <TicketListSkeleton />
                        ) : total === 0 ? (
                            filtered ? (
                                <EmptyState icon={<InboxIcon />} title={text.empty_filtered}>
                                    <Button
                                        variant="outline"
                                        onClick={() => {
                                            visit({});
                                        }}
                                    >
                                        {text.clear}
                                    </Button>
                                </EmptyState>
                            ) : (
                                <EmptyState icon={<InboxIcon />} title={copy.empty_title} detail={can.create ? copy.empty_detail : copy.empty_observer}>
                                    {can.create && (
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                setCreating(true);
                                            }}
                                        >
                                            {copy.new_ticket}
                                        </Button>
                                    )}
                                </EmptyState>
                            )
                        ) : (
                            <TicketList key={query} tickets={tickets} nextCursor={next_cursor} copy={copy} selectedId={selected?.id ?? null} query={query} />
                        )}
                    </div>
                </section>

                <div className={`min-h-0 min-w-0 flex-1 flex-col bg-background ${selected === null ? 'hidden lg:flex' : 'flex'}`}>
                    {selected === null ? (
                        <div className="flex flex-1 items-center justify-center">
                            <EmptyState icon={<ChatIcon />} title={copy.select_hint} />
                        </div>
                    ) : (
                        <>
                            <div className="border-b border-border bg-surface px-4 py-2 lg:hidden">
                                <Link href={`/tickets${query}`} className="inline-flex min-h-11 items-center gap-2 text-text-secondary hover:text-text">
                                    <ArrowLeftIcon className="size-4" />
                                    {copy.back_to_inbox}
                                </Link>
                            </div>
                            <div className="min-h-0 flex-1">
                                <TicketDetail key={selected.id} ticket={selected} assignees={assignees} tagOptions={tag_options} copy={copy} canUpdate={can.update} />
                            </div>
                        </>
                    )}
                </div>
            </div>

            {can.create && (
                <NewTicketDialog
                    open={creating}
                    copy={copy}
                    assignees={assignees}
                    onClose={() => {
                        setCreating(false);
                    }}
                />
            )}
        </PanelLayout>
    );
}
