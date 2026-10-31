import { useId } from 'react';
import type { SelectHTMLAttributes } from 'react';

interface SelectOption {
    value: string;
    label: string;
}

interface SelectFieldProps extends Omit<SelectHTMLAttributes<HTMLSelectElement>, 'id' | 'children'> {
    /** Nombre accesible; solo se ve si visibleLabel es verdadero (en una tabla el contexto ya lo da la columna). */
    label: string;
    visibleLabel?: boolean;
    options: SelectOption[];
}

/** Lista corta de opciones (menos de 8): un select nativo, que ya trae teclado y lectores de pantalla. */
export function SelectField({ label, visibleLabel = false, options, className = '', ...props }: SelectFieldProps) {
    const id = useId();

    return (
        <>
            <label htmlFor={id} className={visibleLabel ? 'text-sm font-medium text-text' : 'sr-only'}>
                {label}
            </label>
            <select
                id={id}
                className={`h-10 rounded-sm border border-border bg-surface px-3 text-base text-text hover:border-border-strong focus:border-2 focus:border-primary disabled:cursor-not-allowed disabled:opacity-45 ${className}`}
                {...props}
            >
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
        </>
    );
}
