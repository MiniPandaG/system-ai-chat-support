import { Head } from '@inertiajs/react';
import { Alert } from '../../components/Alert';

interface ThanksProps {
    copy: { thanks_title: string; thanks: string };
}

/** El agradecimiento después de responder. No lleva el enlace: ya no sirve y no hay nada que mostrar de la respuesta. */
export default function Thanks({ copy }: ThanksProps) {
    return (
        <main className="mx-auto flex w-full max-w-[28rem] flex-col gap-6 px-4 py-16">
            <Head title={copy.thanks_title} />
            <h1 className="font-display text-2xl font-semibold text-text">{copy.thanks_title}</h1>
            <Alert variant="success">{copy.thanks}</Alert>
        </main>
    );
}
