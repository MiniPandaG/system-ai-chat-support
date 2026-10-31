import { useId } from 'react';
import { Button } from './Button';
import { CheckCircleIcon } from './icons';

interface ColorFieldProps {
    label: string;
    help?: string | undefined;
    /** Texto del selector de color, con :label. */
    pickerLabel: string;
    value: string;
    onChange: (value: string) => void;
    /** Resultado de la revisión del servidor para este color; nulo mientras no hay. */
    status: { kind: 'ok' } | { kind: 'issue'; message: string; suggestion: string | null } | { kind: 'format'; message: string } | null;
    useSuggestion: string;
}

/**
 * Selector de color (design/componentes/campo-texto.md, variante color): cuadro de color y hexadecimal editable a mano.
 * El resultado del contraste se asocia al campo y se anuncia al aparecer.
 */
export function ColorField({ label, help, pickerLabel, value, onChange, status, useSuggestion }: ColorFieldProps) {
    const id = useId();
    const messageId = `${id}-message`;
    const invalid = status !== null && status.kind !== 'ok';
    const pickerValue = /^#[0-9A-Fa-f]{6}$/.test(value) ? value : '#000000';

    return (
        <div className="flex flex-col gap-2">
            <label htmlFor={id} className="text-sm font-medium text-text">
                {label}
            </label>
            <div className="flex items-center gap-3">
                <input
                    type="color"
                    aria-label={pickerLabel.replace(':label', label)}
                    value={pickerValue}
                    className="size-10 shrink-0 cursor-pointer rounded-sm border border-border bg-surface p-1"
                    onChange={(event) => {
                        onChange(event.target.value.toUpperCase());
                    }}
                />
                <input
                    id={id}
                    type="text"
                    inputMode="text"
                    autoComplete="off"
                    spellCheck={false}
                    maxLength={7}
                    value={value}
                    aria-invalid={invalid ? true : undefined}
                    aria-describedby={status === null ? undefined : messageId}
                    className={`h-10 w-32 rounded-sm bg-surface px-3 font-mono text-base uppercase text-text hover:border-border-strong focus:border-2 focus:border-primary ${
                        invalid ? 'border-2 border-danger-border' : 'border border-border'
                    }`}
                    onChange={(event) => {
                        onChange(event.target.value);
                    }}
                />
                {status?.kind === 'ok' && <CheckCircleIcon className="size-5 text-success" aria-hidden="true" />}
            </div>
            {help !== undefined && <p className="text-sm text-text-secondary">{help}</p>}
            <div id={messageId} role="status" className="flex flex-col items-start gap-2 text-sm">
                {status !== null && status.kind !== 'ok' && (
                    <>
                        <p className="text-warning">{status.message}</p>
                        {status.kind === 'issue' && status.suggestion !== null && (
                            <Button
                                variant="outline"
                                onClick={() => {
                                    onChange(status.suggestion ?? value);
                                }}
                            >
                                {useSuggestion.replace(':hex', status.suggestion)}
                            </Button>
                        )}
                    </>
                )}
            </div>
        </div>
    );
}
