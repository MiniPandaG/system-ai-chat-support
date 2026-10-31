import { evaluatePassword } from '../lib/password';
import type { PasswordRequirement, StrengthLevel } from '../lib/password';

interface PasswordStrengthProps {
    password: string;
    copy: {
        label: string;
        empty: string;
        weak: string;
        fair: string;
        strong: string;
        missing: string;
        length: string;
        uppercase: string;
        number: string;
    };
}

const filledSegments: Record<StrengthLevel, number> = { empty: 0, weak: 1, fair: 2, strong: 3 };

const segmentColors: Record<StrengthLevel, string> = {
    empty: 'bg-border',
    weak: 'bg-danger',
    fair: 'bg-warning',
    strong: 'bg-success',
};

const requirementOrder: PasswordRequirement[] = ['length', 'uppercase', 'number'];

export function PasswordStrength({ password, copy }: PasswordStrengthProps) {
    const { level, missing } = evaluatePassword(password);
    const filled = filledSegments[level];
    const text =
        level === 'empty' || missing.length === 0
            ? copy[level]
            : `${copy[level]}. ${copy.missing.replace(
                  ':items',
                  requirementOrder
                      .filter((requirement) => missing.includes(requirement))
                      .map((requirement) => copy[requirement])
                      .join(', '),
              )}`;

    return (
        <div className="flex flex-col gap-2">
            <div className="flex gap-1" aria-hidden="true">
                {[0, 1, 2].map((segment) => (
                    <span
                        key={segment}
                        className={`h-1 flex-1 rounded-full ${segment < filled ? segmentColors[level] : 'bg-border'}`}
                    />
                ))}
            </div>
            {/* El estado se dice con texto, no solo con color, y se anuncia mientras se escribe. */}
            <p className="text-sm text-text-secondary" role="status" aria-live="polite">
                <span className="font-medium text-text">{copy.label}: </span>
                {text}
            </p>
        </div>
    );
}
