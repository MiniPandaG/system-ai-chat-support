import { useEffect, useId, useRef, useState } from 'react';
import type { ClipboardEvent, KeyboardEvent } from 'react';

interface OtpInputProps {
    label: string;
    /** Texto de cada casilla para lectores de pantalla, por ejemplo «Dígito 2 de 6». */
    digitLabel: (position: number, total: number) => string;
    length?: number;
    error?: string | undefined;
    disabled?: boolean;
    focusOnMount?: boolean;
    onChange?: (code: string) => void;
    onComplete?: (code: string) => void;
}

const onlyDigits = (text: string) => text.replace(/\D/g, '');

/**
 * Código de un solo uso en casillas de un dígito. Para reiniciarlo se cambia su `key`.
 * Escribir avanza, Retroceso en una casilla vacía vuelve a la anterior y pegar reparte los dígitos.
 */
export function OtpInput({
    label,
    digitLabel,
    length = 6,
    error,
    disabled = false,
    focusOnMount = false,
    onChange,
    onComplete,
}: OtpInputProps) {
    const [digits, setDigits] = useState<string[]>(() => Array.from({ length }, () => ''));
    const inputsRef = useRef<(HTMLInputElement | null)[]>([]);
    const groupId = useId();
    const errorId = `${groupId}-error`;

    // En pantallas de un solo campo el foco empieza en la primera casilla; se hace al montar y no con el atributo autoFocus.
    useEffect(() => {
        if (focusOnMount) {
            inputsRef.current[0]?.focus();
        }
    }, [focusOnMount]);

    const focusAt = (index: number) => {
        const target = inputsRef.current[Math.min(Math.max(index, 0), length - 1)];
        target?.focus();
        target?.select();
    };

    const commit = (next: string[], focusIndex: number) => {
        setDigits(next);
        const code = next.join('');
        onChange?.(code);
        if (code.length === length) {
            onComplete?.(code);
        }
        focusAt(focusIndex);
    };

    const writeFrom = (start: number, text: string) => {
        const incoming = onlyDigits(text).slice(0, length - start);
        if (incoming === '') return;

        const next = [...digits];
        Array.from(incoming).forEach((digit, offset) => {
            next[start + offset] = digit;
        });
        commit(next, start + incoming.length);
    };

    const handleKeyDown = (index: number, event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'Backspace' && (digits[index] ?? '') === '' && index > 0) {
            event.preventDefault();
            const next = [...digits];
            next[index - 1] = '';
            commit(next, index - 1);
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            focusAt(index - 1);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            focusAt(index + 1);
        } else if (event.key === 'Home') {
            event.preventDefault();
            focusAt(0);
        } else if (event.key === 'End') {
            event.preventDefault();
            focusAt(length - 1);
        }
    };

    const handlePaste = (index: number, event: ClipboardEvent<HTMLInputElement>) => {
        event.preventDefault();
        writeFrom(index, event.clipboardData.getData('text'));
    };

    return (
        <div className="flex flex-col gap-2">
            <span id={groupId} className="text-sm font-medium text-text">
                {label}
            </span>
            <div role="group" aria-labelledby={groupId} aria-describedby={error ? errorId : undefined} className="flex gap-2">
                {digits.map((digit, index) => (
                    <input
                        // eslint-disable-next-line @eslint-react/no-array-index-key -- las casillas son posiciones fijas
                        key={index}
                        ref={(element) => {
                            inputsRef.current[index] = element;
                        }}
                        type="text"
                        inputMode="numeric"
                        pattern="[0-9]*"
                        autoComplete={index === 0 ? 'one-time-code' : 'off'}
                        maxLength={1}
                        value={digit}
                        disabled={disabled}
                        aria-label={digitLabel(index + 1, length)}
                        aria-invalid={error ? true : undefined}
                        className={`h-12 w-full min-w-0 rounded-sm bg-surface-sunken text-center font-mono text-xl text-text outline-none disabled:cursor-not-allowed disabled:opacity-60 ${
                            error
                                ? 'border-2 border-danger-border'
                                : 'border border-border hover:border-border-strong focus:border-2 focus:border-primary focus:bg-surface'
                        }`}
                        onChange={(event) => {
                            const typed = onlyDigits(event.target.value);
                            if (typed === '') {
                                const next = [...digits];
                                next[index] = '';
                                commit(next, index);

                                return;
                            }
                            writeFrom(index, typed.slice(-1));
                        }}
                        onKeyDown={(event) => {
                            handleKeyDown(index, event);
                        }}
                        onPaste={(event) => {
                            handlePaste(index, event);
                        }}
                        onFocus={(event) => {
                            event.target.select();
                        }}
                    />
                ))}
            </div>
            {error && (
                <p id={errorId} className="text-sm text-danger">
                    {error}
                </p>
            )}
        </div>
    );
}
