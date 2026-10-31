import { useId, useState } from 'react';
import type { InputHTMLAttributes, Ref } from 'react';
import { AlertTriangleIcon, EyeIcon, EyeOffIcon } from './icons';

interface TextFieldProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'size'> {
    label: string;
    error?: string | undefined;
    help?: string | undefined;
    inputRef?: Ref<HTMLInputElement>;
    /** Solo para type="password": textos del botón que muestra u oculta el valor. */
    showLabel?: string;
    hideLabel?: string;
}

export function TextField({
    label,
    error,
    help,
    inputRef,
    type = 'text',
    showLabel = '',
    hideLabel = '',
    className = '',
    ...props
}: TextFieldProps) {
    const id = useId();
    const helpId = `${id}-help`;
    const errorId = `${id}-error`;
    const [revealed, setRevealed] = useState(false);

    const isPassword = type === 'password';
    const describedBy = [help ? helpId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;

    const borderClasses = error
        ? 'border-2 border-danger-border'
        : 'border border-border hover:border-border-strong focus:border-2 focus:border-primary focus:bg-surface';

    return (
        <div className={`flex flex-col gap-2 ${className}`}>
            <label htmlFor={id} className="text-sm font-medium text-text">
                {label}
            </label>
            <div className="relative">
                <input
                    id={id}
                    ref={inputRef}
                    type={isPassword && revealed ? 'text' : type}
                    aria-invalid={error ? true : undefined}
                    aria-describedby={describedBy}
                    className={`h-10 w-full rounded-sm bg-surface-sunken px-3 font-body text-base text-text outline-none placeholder:text-text-tertiary disabled:cursor-not-allowed disabled:text-text-disabled disabled:opacity-60 ${borderClasses} ${isPassword || error ? 'pr-12' : ''}`}
                    {...props}
                />
                {isPassword ? (
                    <button
                        type="button"
                        onClick={() => {
                            setRevealed((value) => !value);
                        }}
                        aria-label={revealed ? hideLabel : showLabel}
                        aria-pressed={revealed}
                        className="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-sm text-text-secondary hover:text-text"
                    >
                        {revealed ? <EyeOffIcon className="size-5" /> : <EyeIcon className="size-5" />}
                    </button>
                ) : (
                    error && (
                        <AlertTriangleIcon className="pointer-events-none absolute inset-y-0 right-3 my-auto size-4 text-danger" />
                    )
                )}
            </div>
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
    );
}
