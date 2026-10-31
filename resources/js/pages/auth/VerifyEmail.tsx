import { useForm, usePage } from '@inertiajs/react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { AuthLayout } from '../../layouts/AuthLayout';
import type { SharedProps } from '../../types';

interface VerifyCopy {
    title: string;
    detail: string;
    resent: string;
    resend: string;
    resending: string;
    logout: string;
    logging_out: string;
}

export default function VerifyEmail({ copy }: { copy: VerifyCopy }) {
    const { props } = usePage<SharedProps>();
    const resend = useForm({});
    const logout = useForm({});

    return (
        <AuthLayout title={copy.title}>
            <p className="text-text-secondary">{copy.detail}</p>

            {props.flash.status === 'verification-link-sent' && <Alert variant="success">{copy.resent}</Alert>}
            {props.errors.email !== undefined && <Alert variant="danger">{props.errors.email}</Alert>}

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    resend.post('/email/verification-notification');
                }}
            >
                <Button type="submit" size="lg" fullWidth loading={resend.processing} loadingLabel={copy.resending}>
                    {copy.resend}
                </Button>
            </form>

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    logout.post('/logout');
                }}
            >
                <Button type="submit" variant="text" fullWidth loading={logout.processing} loadingLabel={copy.logging_out}>
                    {copy.logout}
                </Button>
            </form>
        </AuthLayout>
    );
}
