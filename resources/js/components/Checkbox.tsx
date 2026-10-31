import { useId } from 'react';
import type { InputHTMLAttributes } from 'react';

interface CheckboxProps extends Omit<InputHTMLAttributes<HTMLInputElement>, 'id' | 'type' | 'size'> {
    label: string;
}

export function Checkbox({ label, className = '', ...props }: CheckboxProps) {
    const id = useId();

    return (
        <div className={`flex items-center gap-3 ${className}`}>
            <span className="relative flex size-[18px] shrink-0 items-center justify-center">
                <input
                    id={id}
                    type="checkbox"
                    className="peer size-[18px] cursor-pointer appearance-none rounded-sm border border-border-strong bg-transparent outline-none hover:border-primary checked:border-primary checked:bg-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus-ring disabled:cursor-not-allowed disabled:opacity-45"
                    {...props}
                />
                <svg
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={3}
                    strokeLinecap="round"
                    strokeLinejoin="round"
                    aria-hidden="true"
                    className="pointer-events-none absolute size-3 text-primary-foreground opacity-0 peer-checked:opacity-100"
                >
                    <path d="m5 12.5 4.5 4.5L19 7.5" />
                </svg>
            </span>
            {/* La etiqueta completa es clicable, no solo el cuadro de 18 px. */}
            <label htmlFor={id} className="cursor-pointer text-base text-text">
                {label}
            </label>
        </div>
    );
}
