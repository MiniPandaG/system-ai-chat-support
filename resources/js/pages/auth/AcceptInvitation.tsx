import { Link, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { SubmitEvent } from 'react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { PasswordStrength } from '../../components/PasswordStrength';
import { TextField } from '../../components/TextField';
import { AuthLayout } from '../../layouts/AuthLayout';
import { evaluatePassword } from '../../lib/password';
import type { SharedProps } from '../../types';

interface InvitationCopy {
    title: string;
    detail: string;
    email: string;
    name: string;
    password: string;
    confirmation: string;
    show_password: string;
    hide_password: string;
    requirements: string;
    required_suffix: string;
    required: string;
    mismatch: string;
    summary: string;
    submit: string;
    submitting: string;
    invalid: string;
    invalid_title: string;
    login: string;
    roles: Record<string, string>;
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

interface AcceptInvitationProps {
    copy: InvitationCopy;
    token: string;
    invitation: { email: string; role: string; company: string } | null;
}

type Field = 'name' | 'password' | 'password_confirmation';
type FieldErrors = Partial<Record<Field, string | undefined>>;

export default function AcceptInvitation({ copy, token, invitation }: AcceptInvitationProps) {
    const { props } = usePage<SharedProps>();
    const { data, setData, post, processing, errors, reset } = useForm({ name: '', password: '', password_confirmation: '' });
    const [clientErrors, setClientErrors] = useState<FieldErrors>({});
    const [summary, setSummary] = useState('');
    const nameRef = useRef<HTMLInputElement>(null);
    const passwordRef = useRef<HTMLInputElement>(null);
    const confirmationRef = useRef<HTMLInputElement>(null);

    if (invitation === null) {
        return (
            <AuthLayout title={copy.invalid_title}>
                <Alert variant="danger">{copy.invalid}</Alert>
                <Link href="/login" className="self-start text-sm text-text-secondary underline hover:text-text">
                    {copy.login}
                </Link>
            </AuthLayout>
        );
    }

    const label = (field: Field) => (field === 'name' ? copy.name : field === 'password' ? copy.password : copy.confirmation);

    const validate = (field: Field): string | undefined => {
        const value = data[field];
        if (value.trim() === '') return field === 'password_confirmation' ? copy.mismatch : copy.required.replace(':field', label(field).toLowerCase());
        if (field === 'password' && evaluatePassword(value).missing.length > 0) return copy.requirements;
        if (field === 'password_confirmation' && value !== data.password) return copy.mismatch;

        return undefined;
    };

    const submit = (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();

        const fields: Field[] = ['name', 'password', 'password_confirmation'];
        const found: FieldErrors = {};
        for (const field of fields) found[field] = validate(field);
        setClientErrors(found);

        const invalid = fields.filter((field) => found[field] !== undefined);
        if (invalid.length > 0) {
            setSummary(copy.summary.replace(':count', String(invalid.length)).replace(':fields', invalid.map((field) => label(field)).join(', ')));
            const first = invalid[0];
            (first === 'name' ? nameRef : first === 'password' ? passwordRef : confirmationRef).current?.focus();

            return;
        }

        setSummary('');
        post(`/invitations/${token}`, {
            onError: () => {
                reset('password', 'password_confirmation');
            },
        });
    };

    const blur = (field: Field) => () => {
        setClientErrors((current) => ({ ...current, [field]: validate(field) }));
    };

    const roleLabel = copy.roles[invitation.role] ?? invitation.role;

    return (
        <AuthLayout title={copy.title}>
            <p className="text-text-secondary">{copy.detail.replace(':company', invitation.company).replace(':role', roleLabel)}</p>

            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                {summary !== '' && <Alert variant="danger">{summary}</Alert>}
                {props.errors.token !== undefined && <Alert variant="danger">{props.errors.token}</Alert>}

                <TextField label={copy.email} name="email" type="email" value={invitation.email} readOnly autoComplete="username" />

                <TextField
                    label={`${copy.name} ${copy.required_suffix}`}
                    name="name"
                    autoComplete="name"
                    value={data.name}
                    inputRef={nameRef}
                    error={clientErrors.name ?? errors.name}
                    onChange={(event) => {
                        setData('name', event.target.value);
                    }}
                    onBlur={blur('name')}
                />

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
        </AuthLayout>
    );
}
