import { useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { Assignee, TicketPriority, TicketsCopy } from '../ticketTypes';
import type { SharedProps } from '../types';
import { Alert } from './Alert';
import { Button } from './Button';
import { CustomerPicker } from './CustomerPicker';
import type { CustomerOption } from './CustomerPicker';
import { Radio } from './Radio';
import { SelectField } from './SelectField';
import { TextAreaField } from './TextAreaField';
import { TextField } from './TextField';

interface NewTicketDialogProps {
    open: boolean;
    copy: TicketsCopy;
    assignees: Assignee[];
    onClose: () => void;
}

type CustomerMode = 'existing' | 'new';

const priorities: TicketPriority[] = ['low', 'normal', 'high', 'urgent'];
const SUBJECT_MAX = 150;
const BODY_MAX = 10000;

/** Formulario para registrar la consulta de un cliente (RF-01 desde el panel). El servidor repite cada validación. */
export function NewTicketDialog({ open, copy, assignees, onClose }: NewTicketDialogProps) {
    const { props } = usePage<SharedProps>();
    const text = copy.new;
    const [mode, setMode] = useState<CustomerMode>('existing');
    const [customer, setCustomer] = useState<CustomerOption | null>(null);
    const form = useForm({
        subject: '',
        description: '',
        priority: 'normal',
        assigned_to: '',
        customer_name: '',
        customer_email: '',
    });
    const dialogRef = useRef<HTMLDialogElement>(null);
    const subjectRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        const dialog = dialogRef.current;
        if (dialog === null) return;

        if (open && !dialog.open) {
            dialog.showModal();
            subjectRef.current?.focus();
        }
        if (!open && dialog.open) dialog.close();
    }, [open]);

    // Al cerrar sin enviar no se conserva el borrador: la próxima vez empieza limpio.
    const close = () => {
        form.reset();
        form.clearErrors();
        setMode('existing');
        setCustomer(null);
        onClose();
    };

    // Solo viajan los datos del cliente del modo elegido: o el identificador de uno existente, o los de uno nuevo.
    form.transform((data) => ({
        subject: data.subject,
        description: data.description,
        priority: data.priority,
        assigned_to: data.assigned_to === '' ? null : Number(data.assigned_to),
        ...(mode === 'existing' ? { customer_id: customer?.id ?? null } : { customer_name: data.customer_name, customer_email: data.customer_email }),
    }));

    const customerError = props.errors.customer_id ?? form.errors.customer_name;
    const canSubmit = form.data.subject.trim() !== '' && form.data.description.trim() !== '' && (mode === 'existing' ? customer !== null : form.data.customer_name.trim() !== '');

    return (
        <dialog
            ref={dialogRef}
            aria-labelledby="new-ticket-title"
            className="m-auto max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-[40rem] overflow-y-auto rounded-lg bg-surface-raised p-6 text-text shadow-lg backdrop:bg-[var(--color-overlay-backdrop)]"
            onCancel={(event) => {
                event.preventDefault();
                if (!form.processing) close();
            }}
        >
            <form
                noValidate
                className="flex flex-col gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/tickets', {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset();
                            setMode('existing');
                            setCustomer(null);
                            onClose();
                        },
                    });
                }}
            >
                <h2 id="new-ticket-title" className="font-display text-lg font-semibold">
                    {text.title}
                </h2>
                <p className="text-text-secondary">{text.detail}</p>

                {props.errors.limit !== undefined && <Alert variant="warning">{props.errors.limit}</Alert>}

                <TextField
                    label={text.subject}
                    help={text.subject_hint}
                    error={form.errors.subject}
                    maxLength={SUBJECT_MAX}
                    inputRef={subjectRef}
                    value={form.data.subject}
                    onChange={(event) => {
                        form.setData('subject', event.target.value);
                    }}
                />

                <TextAreaField
                    label={text.description}
                    help={text.description_hint}
                    error={form.errors.description}
                    maxLength={BODY_MAX}
                    rows={5}
                    value={form.data.description}
                    onChange={(event) => {
                        form.setData('description', event.target.value);
                    }}
                />

                <fieldset className="flex flex-col gap-3">
                    <legend className="mb-1 text-sm font-medium text-text">{copy.from}</legend>
                    <div className="flex flex-wrap gap-x-6 gap-y-2">
                        {(['existing', 'new'] as const).map((option) => (
                            <Radio
                                key={option}
                                name="customer-mode"
                                value={option}
                                checked={mode === option}
                                label={option === 'existing' ? text.customer_existing : text.customer_new}
                                onChange={() => {
                                    setMode(option);
                                }}
                            />
                        ))}
                    </div>

                    {mode === 'existing' ? (
                        <CustomerPicker copy={text} selected={customer} error={customerError} onSelect={setCustomer} />
                    ) : (
                        <div className="flex flex-col gap-4">
                            <TextField
                                label={text.customer_name}
                                error={form.errors.customer_name}
                                maxLength={120}
                                value={form.data.customer_name}
                                onChange={(event) => {
                                    form.setData('customer_name', event.target.value);
                                }}
                            />
                            <TextField
                                label={text.customer_email}
                                help={text.customer_email_hint}
                                error={form.errors.customer_email}
                                type="email"
                                value={form.data.customer_email}
                                onChange={(event) => {
                                    form.setData('customer_email', event.target.value);
                                }}
                            />
                        </div>
                    )}
                </fieldset>

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="flex flex-col gap-2">
                        <SelectField
                            label={copy.priority_label}
                            visibleLabel
                            options={priorities.map((priority) => ({ value: priority, label: copy.priority[priority] }))}
                            value={form.data.priority}
                            onChange={(event) => {
                                form.setData('priority', event.target.value);
                            }}
                        />
                        {form.errors.priority !== undefined && <p className="text-sm text-danger">{form.errors.priority}</p>}
                    </div>
                    <div className="flex flex-col gap-2">
                        <SelectField
                            label={copy.assignee_label}
                            visibleLabel
                            options={[{ value: '', label: copy.unassigned }, ...assignees.map((person) => ({ value: String(person.id), label: person.name }))]}
                            value={form.data.assigned_to}
                            onChange={(event) => {
                                form.setData('assigned_to', event.target.value);
                            }}
                        />
                        {form.errors.assigned_to !== undefined && <p className="text-sm text-danger">{form.errors.assigned_to}</p>}
                    </div>
                </div>

                <div className="flex justify-end gap-3">
                    <Button variant="text" disabled={form.processing} onClick={close}>
                        {text.cancel}
                    </Button>
                    <Button type="submit" loading={form.processing} loadingLabel={text.submitting} disabled={!canSubmit}>
                        {text.submit}
                    </Button>
                </div>
            </form>
        </dialog>
    );
}
