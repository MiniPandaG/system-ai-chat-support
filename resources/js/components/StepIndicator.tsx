import { CheckIcon } from './icons';

interface StepIndicatorProps {
    /** Nombre accesible de la lista de pasos. */
    label: string;
    steps: string[];
    /** Posición del paso actual, desde 0. */
    current: number;
}

export function StepIndicator({ label, steps, current }: StepIndicatorProps) {
    return (
        <ol aria-label={label} className="flex items-center gap-3">
            {steps.map((step, index) => {
                const done = index < current;
                const active = index === current;

                return (
                    <li key={step} aria-current={active ? 'step' : undefined} className="flex items-center gap-2">
                        <span
                            className={`inline-flex size-6 items-center justify-center rounded-full text-sm font-semibold ${
                                active
                                    ? 'bg-primary text-primary-foreground'
                                    : done
                                      ? 'border border-primary text-primary'
                                      : 'border border-border text-text-tertiary'
                            }`}
                        >
                            {done ? <CheckIcon className="size-4" /> : index + 1}
                        </span>
                        <span className={`text-sm font-medium ${active ? 'text-text' : done ? 'text-text-secondary' : 'text-text-tertiary'}`}>
                            {step}
                        </span>
                        {index < steps.length - 1 && <span aria-hidden="true" className="ml-1 h-px w-6 bg-border" />}
                    </li>
                );
            })}
        </ol>
    );
}
