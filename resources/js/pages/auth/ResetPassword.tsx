import { Link, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { SubmitEvent } from 'react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { PasswordStrength } from '../../components/PasswordStrength';
import { TextField } from '../../components/TextField';
import { AuthLayout } from '../../layouts/AuthLayout';
import { evaluatePassword } from '../../lib/password';

interface ResetCopy {
    title: string;
    email: string;
    password: string;
    confirmation: string;
    show_password: string;
    hide_password: string;
    requirements: string;
    required_suffix: string;
    required: string;
    mismatch: string;
    submit: string;
    submitting: string;
    request_new: string;
    summary: string;
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

interface ResetPasswordProps {
    copy: ResetCopy;
    token: string;
    email: string;
}

type Field = 'password' | 'password_confirmation';
type FieldErrors = Partial<Record<Field, string | undefined>>;

export default function ResetPassword({ copy, token, email }: ResetPasswordProps) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token,
        email,
        password: '',
        password_confirmation: '',
    });
    const [clientErrors, setClientErrors] = useState<FieldErrors>({});
    const [summary, setSummary] = useState('');
    const passwordRef = useRef<HTMLInputElement>(null);
    const confirmationRef = useRef<HTMLInputElement>(null);

    const label = (field: Field) => (field === 'password' ? copy.password : copy.confirmation);

    const validate = (field: Field): string | undefined => {
        const value = data[field];
        if (value === '') return copy.required.replace(':field', label(field).toLowerCase());
        if (field === 'password' && evaluatePassword(value).missing.length > 0) return copy.requirements;
        if (field === 'password_confirmation' && value !== data.password) return copy.mismatch;

        return undefined;
    };

    const submit = (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();

        const found: FieldErrors = { password: validate('password'), password_confirmation: validate('password_confirmation') };
        setClientErrors(found);

        const invalid = (Object.keys(found) as Field[]).filter((field) => found[field] !== undefined);
        if (invalid.length > 0) {
            setSummary(
                copy.summary
                    .replace(':count', String(invalid.length))
                    .replace(':fields', invalid.map((field) => label(field)).join(', ')),
            );
            (invalid[0] === 'password' ? passwordRef : confirmationRef).current?.focus();

            return;
        }

        setSummary('');
        post('/reset-password', {
            onError: () => {
                reset('password', 'password_confirmation');
                passwordRef.current?.focus();
            },
        });
    };

    return (
        <AuthLayout title={copy.title}>
            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                {summary !== '' && <Alert variant="danger">{summary}</Alert>}
                {errors.email !== undefined && (
                    <Alert variant="danger">
                        {errors.email}{' '}
                        <Link href="/forgot-password" className="underline">
                            {copy.request_new}
                        </Link>
                    </Alert>
                )}

                <TextField label={copy.email} name="email" type="email" value={data.email} readOnly autoComplete="username" />

                <TextField
                    label={`${copy.password} ${copy.required_suffix}`}
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
                    onBlur={() => {
                        setClientErrors((current) => ({ ...current, password: validate('password') }));
                    }}
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
                    onBlur={() => {
                        setClientErrors((current) => ({
                            ...current,
                            password_confirmation: validate('password_confirmation'),
                        }));
                    }}
                />

                <Button type="submit" size="lg" fullWidth loading={processing} loadingLabel={copy.submitting}>
                    {copy.submit}
                </Button>
            </form>
        </AuthLayout>
    );
}
