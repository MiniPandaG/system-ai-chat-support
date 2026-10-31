import { Link, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { SubmitEvent } from 'react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { TextField } from '../../components/TextField';
import { AuthLayout } from '../../layouts/AuthLayout';
import type { SharedProps } from '../../types';

interface LoginCopy {
    title: string;
    email: string;
    password: string;
    show_password: string;
    hide_password: string;
    submit: string;
    submitting: string;
    required: string;
    required_suffix: string;
    invalid_email: string;
    summary: string;
    forgot: string;
    register_prompt: string;
    register_link: string;
}

interface LoginProps {
    copy: LoginCopy;
}

type Field = 'email' | 'password';
type FieldErrors = Partial<Record<Field, string | undefined>>;

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export default function Login({ copy }: LoginProps) {
    const { props } = usePage<SharedProps>();
    const { data, setData, post, processing, errors, reset } = useForm({ email: '', password: '' });
    const [clientErrors, setClientErrors] = useState<FieldErrors>({});
    const [summary, setSummary] = useState('');
    const emailRef = useRef<HTMLInputElement>(null);
    const passwordRef = useRef<HTMLInputElement>(null);

    const fieldLabel = (field: Field) => (field === 'email' ? copy.email : copy.password);

    const validate = (field: Field, value: string): string | undefined => {
        if (value.trim() === '') {
            return copy.required.replace(':field', fieldLabel(field).toLowerCase());
        }

        if (field === 'email' && !emailPattern.test(value.trim())) {
            return copy.invalid_email;
        }

        return undefined;
    };

    const checkOnBlur = (field: Field) => {
        const message = validate(field, data[field]);
        setClientErrors((current) => ({ ...current, [field]: message }));
    };

    const submit = (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();

        const found: FieldErrors = {};
        const emailMessage = validate('email', data.email);
        const passwordMessage = validate('password', data.password);
        if (emailMessage !== undefined) found.email = emailMessage;
        if (passwordMessage !== undefined) found.password = passwordMessage;

        setClientErrors(found);

        const invalid = (Object.keys(found) as Field[]).filter((field) => found[field] !== undefined);
        if (invalid.length > 0) {
            setSummary(
                copy.summary
                    .replace(':count', String(invalid.length))
                    .replace(':fields', invalid.map((field) => fieldLabel(field)).join(', ')),
            );
            (invalid[0] === 'email' ? emailRef : passwordRef).current?.focus();

            return;
        }

        setSummary('');
        post('/login', {
            onError: () => {
                reset('password');
                passwordRef.current?.focus();
            },
        });
    };

    // El error de credenciales y el de bloqueo llegan en el campo email; se muestran arriba como aviso.
    const credentialsError = errors.email;

    return (
        <AuthLayout title={copy.title}>
            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                {summary !== '' && <Alert variant="danger">{summary}</Alert>}
                {props.flash.status !== null && credentialsError === undefined && <Alert variant="success">{props.flash.status}</Alert>}
                {credentialsError !== undefined && summary === '' && <Alert variant="danger">{credentialsError}</Alert>}

                <TextField
                    label={`${copy.email} ${copy.required_suffix}`}
                    name="email"
                    type="email"
                    autoComplete="username"
                    inputMode="email"
                    value={data.email}
                    inputRef={emailRef}
                    error={clientErrors.email}
                    onChange={(event) => {
                        setData('email', event.target.value);
                    }}
                    onBlur={() => {
                        checkOnBlur('email');
                    }}
                />

                <TextField
                    label={`${copy.password} ${copy.required_suffix}`}
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    value={data.password}
                    inputRef={passwordRef}
                    error={clientErrors.password}
                    showLabel={copy.show_password}
                    hideLabel={copy.hide_password}
                    onChange={(event) => {
                        setData('password', event.target.value);
                    }}
                    onBlur={() => {
                        checkOnBlur('password');
                    }}
                />

                <Link href="/forgot-password" className="-mt-3 self-end text-sm text-text-secondary underline hover:text-text">
                    {copy.forgot}
                </Link>

                <Button type="submit" size="lg" fullWidth loading={processing} loadingLabel={copy.submitting}>
                    {copy.submit}
                </Button>
            </form>

            <p className="text-sm text-text-secondary">
                {copy.register_prompt}{' '}
                <Link href="/register" className="underline hover:text-text">
                    {copy.register_link}
                </Link>
            </p>
        </AuthLayout>
    );
}
