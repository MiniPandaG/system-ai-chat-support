import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { SyntheticEvent } from 'react';
import type { TagData, TagTone, TagsCopy } from '../ticketTypes';
import type { SharedProps } from '../types';
import { Badge } from './Badge';
import { Button } from './Button';
import { PlusIcon } from './icons';
import { SelectField } from './SelectField';
import { TextField } from './TextField';

interface TicketTagsProps {
    ticketId: number;
    tags: TagData[];
    /** Etiquetas de la empresa entre las que elegir; vacío para quien no puede etiquetar. */
    options: TagData[];
    canEdit: boolean;
    copy: TagsCopy;
}

const tones: TagTone[] = ['neutral', 'primary', 'info', 'success', 'warning', 'danger'];
const MAX_NAME = 30;

/**
 * Etiquetas del ticket: insignias removibles y un panel para añadir una existente o crear una nueva en el momento.
 * El color sale de una lista cerrada de tonos; nunca se escribe un color.
 */
export function TicketTags({ ticketId, tags, options, canEdit, copy }: TicketTagsProps) {
    const { props } = usePage<SharedProps>();
    const [open, setOpen] = useState(false);
    const [name, setName] = useState('');
    const [tone, setTone] = useState<TagTone>('neutral');
    const [processing, setProcessing] = useState(false);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const error = props.errors.name ?? props.errors.tag_id ?? props.errors.tone;
    const url = `/tickets/${String(ticketId)}/tags`;
    const available = options.filter((option) => !tags.some((tag) => tag.id === option.id));

    const close = () => {
        setOpen(false);
        triggerRef.current?.focus();
    };

    // Esc cierra el panel desde cualquiera de sus controles y devuelve el foco al botón que lo abrió.
    useEffect(() => {
        if (!open) return;

        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                triggerRef.current?.focus();
            }
        };
        document.addEventListener('keydown', onKeyDown);

        return () => {
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    const attach = (data: { tag_id: number } | { name: string; tone: TagTone }) => {
        setProcessing(true);
        router.post(url, data, {
            preserveScroll: true,
            onSuccess: () => {
                setName('');
                setTone('neutral');
            },
            onFinish: () => {
                setProcessing(false);
            },
        });
    };

    const remove = (tag: TagData) => {
        router.delete(`${url}/${String(tag.id)}`, { preserveScroll: true });
    };

    const create = (event: SyntheticEvent) => {
        event.preventDefault();
        if (name.trim() === '') return;
        attach({ name, tone });
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-2">
                <span className="text-xs text-text-tertiary">{copy.label}:</span>
                {tags.length === 0 && <span className="text-xs text-text-tertiary">{copy.none}</span>}
                {tags.length > 0 && (
                    <ul aria-label={copy.label} className="flex flex-wrap gap-2">
                        {tags.map((tag) => (
                            <li key={tag.id}>
                                {canEdit ? (
                                    <Badge
                                        variant={tag.tone}
                                        shape="tag"
                                        removeLabel={copy.remove.replace(':name', tag.name)}
                                        onRemove={() => {
                                            remove(tag);
                                        }}
                                    >
                                        {tag.name}
                                    </Badge>
                                ) : (
                                    <Badge variant={tag.tone} shape="tag">
                                        {tag.name}
                                    </Badge>
                                )}
                            </li>
                        ))}
                    </ul>
                )}
                {canEdit && (
                    <button
                        ref={triggerRef}
                        type="button"
                        aria-expanded={open}
                        aria-label={copy.add}
                        title={copy.add}
                        onClick={() => {
                            setOpen((value) => !value);
                        }}
                        className="flex size-6 items-center justify-center rounded-sm border border-border-strong text-text-secondary hover:bg-surface-hover hover:text-text"
                    >
                        <PlusIcon className="size-3" />
                    </button>
                )}
            </div>

            {canEdit && open && (
                <div role="group" aria-label={copy.add} className="flex max-h-72 flex-col gap-4 overflow-y-auto rounded-md border border-border bg-surface-raised p-4 shadow-md">
                    <div className="flex flex-col gap-2">
                        <p className="text-sm font-medium text-text">{copy.existing}</p>
                        {available.length === 0 ? (
                            <p className="text-sm text-text-secondary">{copy.existing_none}</p>
                        ) : (
                            <ul className="flex flex-wrap gap-2">
                                {available.map((option) => (
                                    <li key={option.id}>
                                        <button
                                            type="button"
                                            disabled={processing}
                                            onClick={() => {
                                                attach({ tag_id: option.id });
                                            }}
                                            className="rounded-sm disabled:cursor-not-allowed disabled:opacity-45"
                                        >
                                            <Badge variant={option.tone} shape="tag">
                                                {option.name}
                                            </Badge>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <form onSubmit={create} className="flex flex-col gap-3">
                        <p className="text-sm font-medium text-text">{copy.new}</p>
                        <TextField
                            label={copy.name}
                            help={copy.name_hint}
                            error={error}
                            value={name}
                            maxLength={MAX_NAME}
                            autoComplete="off"
                            onChange={(event) => {
                                setName(event.target.value);
                            }}
                        />
                        <div className="flex flex-col gap-2">
                            <SelectField
                                label={copy.tone}
                                visibleLabel
                                options={tones.map((value) => ({ value, label: copy.tones[value] }))}
                                value={tone}
                                onChange={(event) => {
                                    setTone(event.target.value as TagTone);
                                }}
                            />
                        </div>
                        <div className="flex flex-wrap justify-end gap-3">
                            <Button variant="outline" onClick={close}>
                                {copy.close}
                            </Button>
                            <Button type="submit" disabled={name.trim() === ''} loading={processing}>
                                {copy.create}
                            </Button>
                        </div>
                    </form>
                </div>
            )}
        </div>
    );
}
