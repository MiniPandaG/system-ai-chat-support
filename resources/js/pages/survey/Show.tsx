import { Head, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Radio } from '../../components/Radio';
import { TextAreaField } from '../../components/TextAreaField';

interface SurveyCopy {
    heading: string;
    detail: string;
    score_legend: string;
    scale: Record<string, string>;
    comment: string;
    comment_hint: string;
    submit: string;
    submitting: string;
    invalid_title: string;
    invalid: string;
}

interface ShowProps {
    copy: SurveyCopy;
    token: string;
    open: boolean;
    company: string;
    /** El color primario de la empresa y su texto, ya validados por el servidor. */
    theme: Record<string, string>;
    score: number | null;
    comment_max: number;
}

/**
 * La encuesta de satisfacción de un ticket (RF-36). Abrir el enlace solo muestra el formulario; el puntaje que trae el enlace
 * llega marcado y nada se registra hasta enviar. Un enlace falso, caducado o usado muestra la misma página de «ya no sirve».
 */
export default function Show({ copy, token, open, company, theme, score, comment_max }: ShowProps) {
    const form = useForm<{ score: number | null; comment: string }>({ score, comment: '' });

    // Los colores de la empresa se aplican como variables CSS de una lista cerrada que el servidor ya filtró.
    useEffect(() => {
        const root = document.documentElement;
        const names = Object.keys(theme);

        for (const name of names) root.style.setProperty(name, theme[name] ?? '');

        return () => {
            for (const name of names) root.style.removeProperty(name);
        };
    }, [theme]);

    if (!open) {
        return (
            <main className="mx-auto flex w-full max-w-[28rem] flex-col gap-6 px-4 py-16">
                <Head title={copy.invalid_title} />
                <h1 className="font-display text-2xl font-semibold text-text">{copy.invalid_title}</h1>
                <Alert variant="info">{copy.invalid}</Alert>
            </main>
        );
    }

    const scoreError = (form.errors as Record<string, string | undefined>).score;

    return (
        <main className="mx-auto flex w-full max-w-[28rem] flex-col gap-6 px-4 py-16">
            <Head title={copy.heading.replace(':company', company)} />
            <p className="font-display text-lg font-bold text-primary">{company}</p>
            <h1 className="font-display text-2xl font-semibold text-text">{copy.heading.replace(':company', company)}</h1>
            <p className="text-text-secondary">{copy.detail}</p>
            <form
                noValidate
                className="flex flex-col gap-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/survey/${token}`, { preserveScroll: true });
                }}
            >
                <fieldset className="flex flex-col">
                    <legend className="mb-1 text-sm font-medium text-text">{copy.score_legend}</legend>
                    {[1, 2, 3, 4, 5].map((value) => (
                        <Radio
                            key={value}
                            name="score"
                            label={`${String(value)} · ${copy.scale[String(value)] ?? ''}`}
                            checked={form.data.score === value}
                            onChange={() => {
                                form.setData('score', value);
                            }}
                        />
                    ))}
                    {scoreError !== undefined && (
                        <p role="alert" className="text-sm text-danger">
                            {scoreError}
                        </p>
                    )}
                </fieldset>
                <TextAreaField
                    label={copy.comment}
                    help={copy.comment_hint}
                    value={form.data.comment}
                    maxLength={comment_max}
                    error={(form.errors as Record<string, string | undefined>).comment}
                    onChange={(event) => {
                        form.setData('comment', event.target.value);
                    }}
                />
                <div>
                    <Button type="submit" loading={form.processing} loadingLabel={copy.submitting}>
                        {copy.submit}
                    </Button>
                </div>
            </form>
        </main>
    );
}
