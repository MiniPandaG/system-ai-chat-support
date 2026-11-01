import { useId } from 'react';
import type { InputHTMLAttributes } from 'react';

interface RadioProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'type' | 'size'> {
    label: string;
}

/** Una opción entre varias excluyentes (design/componentes/casilla.md): círculo de 18 px y etiqueta clicable completa. */
export function Radio({ label, className = '', ...props }: RadioProps) {
    const id = useId();

    return (
        <div className={`flex min-h-11 items-center gap-3 ${className}`}>
            <span className="relative flex size-[18px] shrink-0 items-center justify-center">
                <input
                    id={id}
                    type="radio"
                    className="peer size-[18px] cursor-pointer appearance-none rounded-full border border-border-strong bg-transparent outline-none hover:border-primary checked:border-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring disabled:cursor-not-allowed disabled:opacity-45"
                    {...props}
                />
                <span aria-hidden="true" className="pointer-events-none absolute size-2 rounded-full bg-primary opacity-0 peer-checked:opacity-100" />
            </span>
            <label htmlFor={id} className="cursor-pointer text-base text-text">
                {label}
            </label>
        </div>
    );
}
