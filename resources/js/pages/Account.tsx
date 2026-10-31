import { Link } from '@inertiajs/react';
import { Alert } from '../components/Alert';
import { PanelLayout } from '../layouts/PanelLayout';

interface AccountProps {
    copy: {
        title: string;
        greeting: string;
        security: string;
    };
    name: string;
    detail: string;
    /** Texto de aviso cuando quedan pocos códigos de recuperación; null si no hay nada que avisar. */
    recoveryWarning: string | null;
}

export default function Account({ copy, name, detail, recoveryWarning }: AccountProps) {
    return (
        <PanelLayout title={copy.title} section="account">
            {recoveryWarning !== null && <Alert variant="warning">{recoveryWarning}</Alert>}
            <p className="text-md">{copy.greeting.replace(':name', name)}</p>
            <p className="text-text-secondary">{detail}</p>
            <Link href="/settings/security" className="self-start text-sm text-text-secondary underline hover:text-text">
                {copy.security}
            </Link>
        </PanelLayout>
    );
}
