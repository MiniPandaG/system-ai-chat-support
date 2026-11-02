import { useEffect, useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Button } from '../components/Button';
import type { WidgetCopy } from './types';

/** El tope de caracteres de un mensaje; el servidor lo vuelve a comprobar. */
export const MAX_LENGTH = 2000;

interface WidgetComposerProps {
    copy: WidgetCopy;
    onSend: (body: string) => void;
}

/**
 * El compositor simplificado del widget: texto y enviar. Enter envía y Mayús + Enter hace un salto de línea. Al aparecer
 * toma el foco (la ventana acaba de abrirse o de empezar la conversación). El texto se vacía solo cuando el mensaje pasa
 * a la conversación como pendiente, y desde ahí lo guarda el propio mensaje pendiente.
 */
export function WidgetComposer({ copy, onSend }: WidgetComposerProps) {
    const id = useId();
    const [value, setValue] = useState('');
    const inputRef = useRef<HTMLTextAreaElement>(null);
    const empty = value.trim() === '';
    const tooLong = value.length > MAX_LENGTH;
    const nearLimit = value.length > MAX_LENGTH * 0.85;

    useEffect(() => {
        inputRef.current?.focus();
    }, []);

    const submit = () => {
        if (empty || tooLong) return;

        onSend(value);
        setValue('');
        inputRef.current?.focus();
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            submit();
        }
    };

    return (
        <form
            className="flex flex-col gap-2 border-t border-border bg-surface p-3"
            onSubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <label htmlFor={id} className="sr-only">
                {copy.composer_label}
            </label>
            <div className="flex items-end gap-2">
                <textarea
                    id={id}
                    ref={inputRef}
                    rows={2}
                    value={value}
                    placeholder={copy.composer_placeholder}
                    aria-invalid={tooLong ? true : undefined}
                    aria-describedby={nearLimit ? `${id}-count` : undefined}
                    onChange={(event) => {
                        setValue(event.target.value);
                    }}
                    onKeyDown={onKeyDown}
                    className={`max-h-32 min-h-12 w-full resize-none rounded-sm bg-surface-sunken px-3 py-2 font-body text-base text-text outline-none placeholder:text-text-tertiary ${
                        tooLong ? 'border-2 border-danger-border' : 'border border-border hover:border-border-strong focus:border-primary'
                    }`}
                />
                <Button type="submit" disabled={empty || tooLong}>
                    {copy.composer_send}
                </Button>
            </div>
            {nearLimit && (
                <p id={`${id}-count`} role={tooLong ? 'alert' : undefined} className={`text-xs ${tooLong ? 'text-danger' : 'text-text-tertiary'}`}>
                    {tooLong ? copy.too_long.replace(':max', String(MAX_LENGTH)) : copy.characters_left.replace(':count', String(MAX_LENGTH - value.length))}
                </p>
            )}
        </form>
    );
}
