import { Link, useForm } from '@inertiajs/react';
import { Alert } from '../components/Alert';
import { Button } from '../components/Button';
import { AuthLayout } from '../layouts/AuthLayout';

interface AccountProps {
    copy: {
        title: string;
        greeting: string;
        detail: string;
        security: string;
        logout: string;
        logging_out: string;
    };
    name: string;
    /** Texto de aviso cuando quedan pocos códigos de recuperación; null si no hay nada que avisar. */
    recoveryWarning: string | null;
}

export default function Account({ copy, name, recoveryWarning }: AccountProps) {
    const { post, processing } = useForm({});

    return (
        <AuthLayout title={copy.title}>
            {recoveryWarning !== null && <Alert variant="warning">{recoveryWarning}</Alert>}
            <p className="text-md">{copy.greeting.replace(':name', name)}</p>
            <p className="text-text-secondary">{copy.detail}</p>
            <Link href="/settings/security" className="self-start text-sm text-text-secondary underline hover:text-text">
                {copy.security}
            </Link>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    post('/logout');
                }}
            >
                <Button type="submit" variant="outline" size="lg" fullWidth loading={processing} loadingLabel={copy.logging_out}>
                    {copy.logout}
                </Button>
            </form>
        </AuthLayout>
    );
}
