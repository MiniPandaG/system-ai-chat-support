import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { SubmitEvent } from 'react';
import { evaluatePassword } from '../lib/password';
import { Alert } from './Alert';
import { Button } from './Button';
import { PasswordStrength } from './PasswordStrength';
import { TextField } from './TextField';

export interface PasswordChangeCopy {
    title: string;
    detail: string;
    current: string;
    new: string;
    confirmation: string;
    show_password: string;
    hide_password: string;
    requirements: string;
    required_suffix: string;
    required: string;
    mismatch: string;
    same: string;
    summary: string;
    submit: string;
    submitting: string;
    strength: {
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

type Field = 'current_password' | 'password' | 'password_confirmation';
type FieldErrors = Partial<Record<Field, string | undefined>>;

export function PasswordChangeForm({ copy }: { copy: PasswordChangeCopy }) {
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const [clientErrors, setClientErrors] = useState<FieldErrors>({});
    const [summary, setSummary] = useState('');
    const currentRef = useRef<HTMLInputElement>(null);
    const passwordRef = useRef<HTMLInputElement>(null);
    const confirmationRef = useRef<HTMLInputElement>(null);

    const focusField = (field: Field) => {
        const target = field === 'current_password' ? currentRef : field === 'password' ? passwordRef : confirmationRef;
        target.current?.focus();
    };

    const label = (field: Field) => (field === 'current_password' ? copy.current : field === 'password' ? copy.new : copy.confirmation);

    const validate = (field: Field): string | undefined => {
        const value = data[field];
        if (value === '') return copy.required.replace(':field', label(field).toLowerCase());
        if (field === 'password' && evaluatePassword(value).missing.length > 0) return copy.requirements;
        if (field === 'password' && value === data.current_password) return copy.same;
        if (field === 'password_confirmation' && value !== data.password) return copy.mismatch;

        return undefined;
    };

    const submit = (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();

        const fields: Field[] = ['current_password', 'password', 'password_confirmation'];
        const found: FieldErrors = {};
        for (const field of fields) found[field] = validate(field);
        setClientErrors(found);

        const invalid = fields.filter((field) => found[field] !== undefined);
        if (invalid.length > 0) {
            setSummary(copy.summary.replace(':count', String(invalid.length)).replace(':fields', invalid.map((field) => label(field)).join(', ')));
            focusField(invalid[0] ?? 'current_password');

            return;
        }

        setSummary('');
        put('/settings/password', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setClientErrors({});
            },
            onError: (serverErrors) => {
                reset('password', 'password_confirmation');
                focusField(serverErrors.current_password === undefined ? 'password' : 'current_password');
            },
        });
    };

    const blur = (field: Field) => () => {
        setClientErrors((current) => ({ ...current, [field]: validate(field) }));
    };

    return (
        <section aria-labelledby="contrasena" className="flex flex-col gap-4">
            <div className="flex flex-col gap-2">
                <h2 id="contrasena" className="font-display text-xl font-semibold">
                    {copy.title}
                </h2>
                <p className="text-text-secondary">{copy.detail}</p>
            </div>

            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                {summary !== '' && <Alert variant="danger">{summary}</Alert>}

                <TextField
                    label={`${copy.current} ${copy.required_suffix}`}
                    name="current_password"
                    type="password"
                    autoComplete="current-password"
                    value={data.current_password}
                    inputRef={currentRef}
                    error={clientErrors.current_password ?? errors.current_password}
                    showLabel={copy.show_password}
                    hideLabel={copy.hide_password}
                    onChange={(event) => {
                        setData('current_password', event.target.value);
                    }}
                    onBlur={blur('current_password')}
                />

                <TextField
                    label={`${copy.new} ${copy.required_suffix}`}
                    name="password"
                    type="password"
                    autoComplete="new-password"
                    value={data.password}
                    inputRef={passwordRef}
                    help={copy.requirements}
                    error={clientErrors.password ?? errors.password}
                    showLabel={copy.show_password}
                    hideLabel={copy.hide_password}
                    onChange={(event) => {
                        setData('password', event.target.value);
                    }}
                    onBlur={blur('password')}
                />

                <PasswordStrength password={data.password} copy={copy.strength} />

                <TextField
                    label={`${copy.confirmation} ${copy.required_suffix}`}
                    name="password_confirmation"
                    type="password"
                    autoComplete="new-password"
                    value={data.password_confirmation}
                    inputRef={confirmationRef}
                    error={clientErrors.password_confirmation}
                    showLabel={copy.show_password}
                    hideLabel={copy.hide_password}
                    onChange={(event) => {
                        setData('password_confirmation', event.target.value);
                    }}
                    onBlur={blur('password_confirmation')}
                />

                <Button type="submit" size="lg" fullWidth loading={processing} loadingLabel={copy.submitting}>
                    {copy.submit}
                </Button>
            </form>
        </section>
    );
}
