import { useId } from 'react';
import type { Ref, TextareaHTMLAttributes } from 'react';
import { AlertTriangleIcon } from './icons';

interface TextAreaFieldProps extends Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'id'> {
    label: string;
    error?: string | undefined;
    help?: string | undefined;
    inputRef?: Ref<HTMLTextAreaElement>;
    /** Con un máximo, muestra el contador de caracteres; pasa a color de peligro solo al excederlo. */
    maxLength?: number;
}

/** Variante textarea de design/componentes/campo-texto.md: alto mínimo de 80 px, redimensionable solo en vertical. */
export function TextAreaField({ label, error, help, inputRef, maxLength, value, className = '', ...props }: TextAreaFieldProps) {
    const id = useId();
    const helpId = `${id}-help`;
    const errorId = `${id}-error`;
    const describedBy = [help ? helpId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;
    const length = typeof value === 'string' ? value.length : 0;

    const borderClasses = error
        ? 'border-2 border-danger-border'
        : 'border border-border hover:border-border-strong focus:border-2 focus:border-primary focus:bg-surface';

    return (
        <div className={`flex flex-col gap-2 ${className}`}>
            <label htmlFor={id} className="text-sm font-medium text-text">
                {label}
            </label>
            <div className="relative">
                <textarea
                    id={id}
                    ref={inputRef}
                    value={value}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                    className={`min-h-20 w-full resize-y rounded-sm bg-surface-sunken px-3 py-2 font-body text-base text-text outline-none placeholder:text-text-tertiary disabled:cursor-not-allowed disabled:text-text-disabled disabled:opacity-60 ${borderClasses}`}
                    {...props}
                />
                {error && <AlertTriangleIcon className="pointer-events-none absolute right-3 top-3 size-4 text-danger" />}
            </div>
            <div className="flex items-start justify-between gap-3">
                <div className="flex flex-col gap-2">
                    {help && (
                        <p id={helpId} className="text-sm text-text-secondary">
                            {help}
                        </p>
                    )}
                    {error && (
                        <p id={errorId} className="text-sm text-danger">
                            {error}
                        </p>
                    )}
                </div>
                {maxLength !== undefined && (
                    <p className={`ml-auto shrink-0 text-xs ${length > maxLength ? 'text-danger' : 'text-text-tertiary'}`}>
                        {length} / {maxLength}
                    </p>
                )}
            </div>
        </div>
    );
}
