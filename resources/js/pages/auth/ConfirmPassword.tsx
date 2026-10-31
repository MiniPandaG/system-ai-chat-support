import { useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { SubmitEvent } from 'react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { TextField } from '../../components/TextField';
import { AuthLayout } from '../../layouts/AuthLayout';

interface ConfirmCopy {
    title: string;
    detail: string;
    password: string;
    show_password: string;
    hide_password: string;
    required_suffix: string;
    required: string;
    submit: string;
    submitting: string;
}

export default function ConfirmPassword({ copy }: { copy: ConfirmCopy }) {
    const { data, setData, post, processing, errors, reset } = useForm({ password: '' });
    const [clientError, setClientError] = useState<string | undefined>(undefined);
    const passwordRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        passwordRef.current?.focus();
    }, []);

    const submit = (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (data.password === '') {
            setClientError(copy.required);
            passwordRef.current?.focus();

            return;
        }

        setClientError(undefined);
        post('/user/confirm-password', {
            onError: () => {
                reset('password');
                passwordRef.current?.focus();
            },
        });
    };

    return (
        <AuthLayout title={copy.title}>
            <p className="text-text-secondary">{copy.detail}</p>
            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                {errors.password !== undefined && <Alert variant="danger">{errors.password}</Alert>}

                <TextField
                    label={`${copy.password} ${copy.required_suffix}`}
                    name="password"
                    type="password"
                    autoComplete="current-password"
                    value={data.password}
                    inputRef={passwordRef}
                    error={clientError}
                    showLabel={copy.show_password}
                    hideLabel={copy.hide_password}
                    onChange={(event) => {
                        setData('password', event.target.value);
                    }}
                />

                <Button type="submit" size="lg" fullWidth loading={processing} loadingLabel={copy.submitting}>
                    {copy.submit}
                </Button>
            </form>
        </AuthLayout>
    );
}
