import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { SyntheticEvent } from 'react';
import { inboxParams, isFiltered } from '../lib/inbox';
import type { Assignee, InboxFilters, TagData, TicketPriority, TicketStatus, TicketsCopy } from '../ticketTypes';
import type { SharedProps } from '../types';
import { Button } from './Button';
import { Checkbox } from './Checkbox';
import { SelectField } from './SelectField';
import { TextField } from './TextField';

interface InboxFilterPanelProps {
    id: string;
    copy: TicketsCopy;
    filters: InboxFilters;
    assignees: Assignee[];
    tags: TagData[];
    canSaveView: boolean;
    /** Cambia los filtros (sin vista guardada): la lista se actualiza en vivo mientras se ajustan. */
    onChange: (changes: Partial<InboxFilters>) => void;
    onClear: () => void;
}

const statuses: TicketStatus[] = ['open', 'waiting_customer', 'resolved', 'closed'];
const priorities: TicketPriority[] = ['low', 'normal', 'high', 'urgent'];

function toggle<T>(list: T[] | undefined, value: T): T[] {
    const current = list ?? [];

    return current.includes(value) ? current.filter((item) => item !== value) : [...current, value];
}

/**
 * Panel de filtros avanzados (design/pantallas/bandeja-tickets.md): no es un modal, para ver la lista cambiar mientras
 * se ajustan. Al fondo permite guardar la combinación como vista, privada o compartida con el equipo.
 */
export function InboxFilterPanel({ id, copy, filters, assignees, tags, canSaveView, onChange, onClear }: InboxFilterPanelProps) {
    const { props } = usePage<SharedProps>();
    const text = copy.inbox;
    const [saving, setSaving] = useState(false);
    const [name, setName] = useState('');
    const [shared, setShared] = useState(false);
    const [processing, setProcessing] = useState(false);
    // Los errores de guardar una vista llegan por el nombre; los demás campos del panel no tienen errores propios.
    const error = props.errors.name;

    const save = (event: SyntheticEvent) => {
        event.preventDefault();
        if (name.trim() === '' || processing) return;

        setProcessing(true);
        router.post(
            '/tickets/views',
            { ...inboxParams({ ...filters, saved: null }), name, shared },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSaving(false);
                    setName('');
                    setShared(false);
                },
                onFinish: () => {
                    setProcessing(false);
                },
            },
        );
    };

    return (
        <section id={id} aria-label={text.panel_label} className="flex max-h-[60vh] flex-col gap-4 overflow-y-auto border-b border-border bg-surface-raised p-4">
            <div className="grid grid-cols-2 gap-4">
                <fieldset className="flex flex-col gap-1">
                    <legend className="mb-1 text-sm font-medium text-text">{text.status}</legend>
                    {statuses.map((status) => (
                        <Checkbox
                            key={status}
                            label={copy.status[status]}
                            checked={filters.status?.includes(status) ?? false}
                            onChange={() => {
                                onChange({ status: toggle(filters.status, status) });
                            }}
                        />
                    ))}
                </fieldset>

                <fieldset className="flex flex-col gap-1">
                    <legend className="mb-1 text-sm font-medium text-text">{text.priority}</legend>
                    {priorities.map((priority) => (
                        <Checkbox
                            key={priority}
                            label={copy.priority[priority]}
                            checked={filters.priority?.includes(priority) ?? false}
                            onChange={() => {
                                onChange({ priority: toggle(filters.priority, priority) });
                            }}
                        />
                    ))}
                </fieldset>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="flex flex-col gap-2">
                    <SelectField
                        label={text.agent}
                        visibleLabel
                        options={[{ value: '', label: text.any_agent }, ...assignees.map((person) => ({ value: String(person.id), label: person.active ? person.name : copy.assignee_inactive.replace(':name', person.name) }))]}
                        value={filters.agent === undefined ? '' : String(filters.agent)}
                        onChange={(event) => {
                            onChange({ agent: event.target.value === '' ? undefined : Number(event.target.value) });
                        }}
                    />
                </div>

                <div className="flex flex-col gap-2">
                    <SelectField
                        label={text.tag}
                        visibleLabel
                        options={[{ value: '', label: text.any_tag }, ...tags.map((tag) => ({ value: String(tag.id), label: tag.name }))]}
                        value={filters.tag === undefined ? '' : String(filters.tag)}
                        onChange={(event) => {
                            onChange({ tag: event.target.value === '' ? undefined : Number(event.target.value) });
                        }}
                    />
                </div>
            </div>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <TextField
                    label={text.from}
                    type="date"
                    value={filters.from ?? ''}
                    onChange={(event) => {
                        const from = event.target.value === '' ? undefined : event.target.value;
                        if (from !== filters.from) onChange({ from });
                    }}
                />
                <TextField
                    label={text.to}
                    type="date"
                    value={filters.to ?? ''}
                    onChange={(event) => {
                        const to = event.target.value === '' ? undefined : event.target.value;
                        if (to !== filters.to) onChange({ to });
                    }}
                />
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4">
                <Button variant="text" disabled={!isFiltered(filters)} onClick={onClear}>
                    {text.clear}
                </Button>
                {canSaveView && !saving && (
                    <Button
                        variant="outline"
                        disabled={!isFiltered({ ...filters, q: undefined })}
                        onClick={() => {
                            setSaving(true);
                        }}
                    >
                        {text.save.button}
                    </Button>
                )}
            </div>

            {canSaveView && saving && (
                <form onSubmit={save} className="flex flex-col gap-3 rounded-md border border-border bg-surface p-4">
                    <TextField label={text.save.name} help={text.save.name_hint} error={error} value={name} maxLength={60} autoComplete="off" onChange={(event) => {
                        setName(event.target.value);
                    }} />
                    <div className="flex flex-col gap-1">
                        <Checkbox
                            label={text.save.shared}
                            checked={shared}
                            onChange={(event) => {
                                setShared(event.target.checked);
                            }}
                        />
                        <p className="pl-[30px] text-sm text-text-secondary">{text.save.shared_hint}</p>
                    </div>
                    <div className="flex flex-wrap justify-end gap-3">
                        <Button
                            variant="outline"
                            onClick={() => {
                                setSaving(false);
                            }}
                        >
                            {text.save.cancel}
                        </Button>
                        <Button type="submit" disabled={name.trim() === ''} loading={processing} loadingLabel={text.save.submitting}>
                            {text.save.submit}
                        </Button>
                    </div>
                </form>
            )}
        </section>
    );
}
