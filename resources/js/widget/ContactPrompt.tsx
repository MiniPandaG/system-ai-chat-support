import { useId, useState } from 'react';
import { Button } from '../components/Button';
import type { WidgetCopy } from './types';

interface ContactPromptProps {
    copy: WidgetCopy;
    error: string | null;
    onSave: (email: string) => Promise<boolean>;
    onDismiss: () => void;
}

/**
 * Pide, sin obligar, el correo del visitante. Se ofrece después de su primer mensaje para que escribir nunca dependa de
 * dar un dato. El error del servidor (correo mal escrito) queda junto al campo.
 */
export function ContactPrompt({ copy, error, onSave, onDismiss }: ContactPromptProps) {
    const id = useId();
    const [email, setEmail] = useState('');
    const [saving, setSaving] = useState(false);

    return (
        <form
            noValidate
            className="flex flex-col gap-2 rounded-md border border-border bg-surface-sunken p-3"
            onSubmit={(event) => {
                event.preventDefault();
                if (email.trim() === '' || saving) return;

                setSaving(true);
                void onSave(email.trim()).finally(() => {
                    setSaving(false);
                });
            }}
        >
            <p className="text-sm font-medium text-text">{copy.contact_title}</p>
            <p id={`${id}-help`} className="text-sm text-text-secondary">
                {copy.contact_help}
            </p>
            <label htmlFor={id} className="text-sm font-medium text-text">
                {copy.contact_label}
            </label>
            <input
                id={id}
                type="email"
                inputMode="email"
                autoComplete="email"
                value={email}
                aria-invalid={error ? true : undefined}
                aria-describedby={error ? `${id}-help ${id}-error` : `${id}-help`}
                onChange={(event) => {
                    setEmail(event.target.value);
                }}
                className={`h-10 w-full rounded-sm bg-surface px-3 font-body text-base text-text outline-none placeholder:text-text-tertiary ${
                    error ? 'border-2 border-danger-border' : 'border border-border hover:border-border-strong focus:border-primary'
                }`}
            />
            {error !== null && error !== '' && (
                <p id={`${id}-error`} className="text-sm text-danger">
                    {error}
                </p>
            )}
            <div className="flex gap-2">
                <Button type="submit" loading={saving} loadingLabel={copy.composer_sending} disabled={email.trim() === ''}>
                    {copy.contact_save}
                </Button>
                <Button variant="text" onClick={onDismiss}>
                    {copy.contact_dismiss}
                </Button>
            </div>
        </form>
    );
}
