import { useId, useState } from 'react';
import type { ClipboardEvent, KeyboardEvent } from 'react';
import { XIcon } from './icons';

interface EmailChipsProps {
    label: string;
    hint: string;
    emails: string[];
    onChange: (emails: string[]) => void;
    error?: string | undefined;
    /** Texto del botón de quitar un correo, con :email. */
    removeLabel: string;
    /** Aviso cuando algo escrito no es un correo, con :email. */
    invalidLabel: string;
    inputRef?: React.Ref<HTMLInputElement>;
}

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/**
 * Varios correos como insignias removibles. Se separan con coma, espacio, punto y coma o Enter; lo que no sea un
 * correo válido se queda escrito y se avisa, para que se pueda corregir.
 */
export function EmailChips({ label, hint, emails, onChange, error, removeLabel, invalidLabel, inputRef }: EmailChipsProps) {
    const id = useId();
    const [draft, setDraft] = useState('');
    const [invalid, setInvalid] = useState<string | null>(null);

    const commit = (text: string) => {
        const pieces = text.split(/[\s,;]+/).filter((piece) => piece !== '');
        const accepted = [...emails];
        const rejected: string[] = [];

        for (const piece of pieces) {
            const email = piece.toLowerCase();
            if (!emailPattern.test(email)) rejected.push(piece);
            else if (!accepted.includes(email)) accepted.push(email);
        }

        if (accepted.length !== emails.length) onChange(accepted);
        setDraft(rejected.join(' '));
        setInvalid(rejected[0] ?? null);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Enter' || event.key === ',' || event.key === ' ' || event.key === ';') {
            if (draft.trim() !== '') {
                event.preventDefault();
                commit(draft);
            } else if (event.key === 'Enter') {
                // Un Enter con el campo vacío envía el formulario, como cualquier campo de texto.
                return;
            } else {
                event.preventDefault();
            }
        } else if (event.key === 'Backspace' && draft === '' && emails.length > 0) {
            onChange(emails.slice(0, -1));
        }
    };

    const onPaste = (event: ClipboardEvent<HTMLInputElement>) => {
        event.preventDefault();
        commit(`${draft} ${event.clipboardData.getData('text')}`);
    };

    const message = invalid === null ? error : invalidLabel.replace(':email', invalid);
    const describedBy = `${id}-hint${message === undefined ? '' : ` ${id}-error`}`;

    return (
        <div className="flex flex-col gap-2">
            <label htmlFor={id} className="text-sm font-medium text-text">
                {label}
            </label>
            <div
                className={`flex flex-wrap items-center gap-2 rounded-sm bg-surface p-2 ${
                    message === undefined ? 'border border-border focus-within:border-2 focus-within:border-primary' : 'border-2 border-danger-border'
                }`}
            >
                {emails.length > 0 && (
                    <ul aria-label={label} className="contents">
                        {emails.map((email) => (
                            <li key={email} className="inline-flex h-7 items-center gap-1 rounded-sm border border-border bg-surface-raised pl-2 text-sm">
                                {email}
                                <button
                                    type="button"
                                    aria-label={removeLabel.replace(':email', email)}
                                    className="inline-flex size-7 items-center justify-center rounded-sm text-text-secondary hover:text-text"
                                    onClick={() => {
                                        onChange(emails.filter((candidate) => candidate !== email));
                                    }}
                                >
                                    <XIcon className="size-3.5" />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
                <input
                    id={id}
                    ref={inputRef}
                    type="email"
                    inputMode="email"
                    autoComplete="off"
                    value={draft}
                    aria-describedby={describedBy}
                    aria-invalid={message === undefined ? undefined : true}
                    className="min-w-40 flex-1 bg-transparent px-1 py-1 text-base text-text outline-none"
                    onChange={(event) => {
                        setDraft(event.target.value);
                        setInvalid(null);
                    }}
                    onKeyDown={onKeyDown}
                    onPaste={onPaste}
                    onBlur={() => {
                        if (draft.trim() !== '') commit(draft);
                    }}
                />
            </div>
            <p id={`${id}-hint`} className="text-sm text-text-secondary">
                {hint}
            </p>
            {message !== undefined && (
                <p id={`${id}-error`} role="alert" className="text-sm text-danger">
                    {message}
                </p>
            )}
        </div>
    );
}
