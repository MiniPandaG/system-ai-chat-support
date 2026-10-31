import { Link, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { SubmitEvent } from 'react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { TextField } from '../../components/TextField';
import { AuthLayout } from '../../layouts/AuthLayout';
import type { SharedProps } from '../../types';

interface ForgotCopy {
    title: string;
    detail: string;
    email: string;
    required_suffix: string;
    required: string;
    invalid_email: string;
    submit: string;
    submitting: string;
    back: string;
    sent_title: string;
}

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export default function ForgotPassword({ copy }: { copy: ForgotCopy }) {
    const { props } = usePage<SharedProps>();
    const { data, setData, post, processing, errors } = useForm({ email: '' });
    const [clientError, setClientError] = useState<string | undefined>(undefined);
    const emailRef = useRef<HTMLInputElement>(null);

    const validate = (value: string): string | undefined => {
        if (value.trim() === '') return copy.required;
        if (!emailPattern.test(value.trim())) return copy.invalid_email;

        return undefined;
    };

    const submit = (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();

        const message = validate(data.email);
        setClientError(message);
        if (message !== undefined) {
            emailRef.current?.focus();

            return;
        }

        post('/forgot-password');
    };

    // Mismo texto exista o no la cuenta: el servidor responde igual en los dos casos.
    if (props.flash.status !== null && errors.email === undefined) {
        return (
            <AuthLayout title={copy.sent_title}>
                <Alert variant="success">{props.flash.status}</Alert>
                <Link href="/login" className="text-sm text-text-secondary underline hover:text-text">
                    {copy.back}
                </Link>
            </AuthLayout>
        );
    }

    return (
        <AuthLayout title={copy.title}>
            <p className="text-text-secondary">{copy.detail}</p>
            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                {errors.email !== undefined && <Alert variant="danger">{errors.email}</Alert>}

                <TextField
                    label={`${copy.email} ${copy.required_suffix}`}
                    name="email"
                    type="email"
                    autoComplete="email"
                    inputMode="email"
                    value={data.email}
                    inputRef={emailRef}
                    error={clientError}
                    onChange={(event) => {
                        setData('email', event.target.value);
                    }}
                    onBlur={() => {
                        setClientError(validate(data.email));
                    }}
                />

                <Button type="submit" size="lg" fullWidth loading={processing} loadingLabel={copy.submitting}>
                    {copy.submit}
                </Button>
            </form>
            <Link href="/login" className="text-sm text-text-secondary underline hover:text-text">
                {copy.back}
            </Link>
        </AuthLayout>
    );
}
