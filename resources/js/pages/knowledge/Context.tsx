import { useForm, usePage } from '@inertiajs/react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { KnowledgeTabs } from '../../components/KnowledgeTabs';
import { TextAreaField } from '../../components/TextAreaField';
import { PanelLayout } from '../../layouts/PanelLayout';
import type { SharedProps } from '../../types';

interface ContextCopy {
    title: string;
    detail: string;
    tabs_label: string;
    tab_articles: string;
    tab_documents: string;
    tab_context: string;
    label: string;
    help: string;
    save: string;
    saving: string;
    saved: string;
    unsaved: string;
    read_only: string;
    empty_readonly: string;
}

interface ContextProps {
    copy: ContextCopy;
    body: string;
    can_edit: boolean;
    max: number;
}

/**
 * El contexto de la empresa (RF-18): lo que la IA recibe siempre, antes de buscar. Guardado explícito, como todo formulario
 * de configuración (design/patrones/formularios.md): un cambio de contexto cambia lo que la IA dice en nombre de la empresa.
 */
export default function Context({ copy, body, can_edit: canEdit, max }: ContextProps) {
    const { props } = usePage<SharedProps>();
    const form = useForm({ body });
    const dirty = form.isDirty;

    return (
        <PanelLayout title={copy.title} section="knowledge" wide>
            <div className="flex flex-col gap-3">
                <p className="text-text-secondary">{copy.detail}</p>
                <KnowledgeTabs current="context" label={copy.tabs_label} articles={copy.tab_articles} documents={copy.tab_documents} context={copy.tab_context} />
            </div>

            {props.flash.status !== null && <Alert variant="success">{props.flash.status}</Alert>}

            {canEdit ? (
                <form
                    noValidate
                    className="mx-auto flex w-full max-w-[45rem] flex-col gap-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put('/knowledge/context', {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.setDefaults();
                            },
                        });
                    }}
                >
                    <TextAreaField
                        label={copy.label}
                        help={copy.help}
                        rows={12}
                        maxLength={max}
                        value={form.data.body}
                        error={form.errors.body}
                        onChange={(event) => {
                            form.setData('body', event.target.value);
                        }}
                    />
                    <div className="flex flex-wrap items-center gap-3">
                        <Button type="submit" loading={form.processing} loadingLabel={copy.saving} disabled={!dirty}>
                            {copy.save}
                        </Button>
                        <p role="status" className="text-sm text-warning">
                            {dirty ? copy.unsaved : ''}
                        </p>
                    </div>
                </form>
            ) : (
                <section aria-label={copy.label} className="mx-auto flex w-full max-w-[45rem] flex-col gap-3">
                    <Alert variant="info">{copy.read_only}</Alert>
                    {body === '' ? <p className="text-text-secondary">{copy.empty_readonly}</p> : <pre className="whitespace-pre-wrap break-words rounded-sm border border-border bg-surface p-4 font-body text-base text-text">{body}</pre>}
                </section>
            )}
        </PanelLayout>
    );
}
