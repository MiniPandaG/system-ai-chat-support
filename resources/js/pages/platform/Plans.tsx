import { router, useForm, usePage } from '@inertiajs/react';
import type { SubmitEvent } from 'react';
import { Alert } from '../../components/Alert';
import { Badge } from '../../components/Badge';
import { Button } from '../../components/Button';
import { Checkbox } from '../../components/Checkbox';
import { TextField } from '../../components/TextField';
import { PanelLayout } from '../../layouts/PanelLayout';
import type { SharedProps } from '../../types';

type LimitKey = 'agents' | 'channels' | 'tickets_per_month' | 'ai_credits';

/** El nombre del campo del formulario de cada límite (coincide con la columna de la tabla de planes). */
const limitFields: Record<LimitKey, 'max_agents' | 'max_channels' | 'max_tickets_per_month' | 'ai_credits_per_month'> = {
    agents: 'max_agents',
    channels: 'max_channels',
    tickets_per_month: 'max_tickets_per_month',
    ai_credits: 'ai_credits_per_month',
};

const limitKeys: LimitKey[] = ['agents', 'channels', 'tickets_per_month', 'ai_credits'];

interface PlanRow {
    id: number;
    slug: string;
    name: string;
    is_default: boolean;
    is_active: boolean;
    tenants: number;
    limits: Record<LimitKey, number | null>;
}

interface PlansCopy {
    title: string;
    detail: string;
    new_title: string;
    new_detail: string;
    slug: string;
    name: string;
    active: string;
    save: string;
    saving: string;
    create: string;
    creating: string;
    make_default: string;
    making_default: string;
    is_default: string;
    inactive: string;
    tenants_one: string;
    tenants_many: string;
    tenants_none: string;
    limit_help: string;
    labels: Record<LimitKey, string>;
}

interface PlansProps {
    copy: PlansCopy;
    plans: PlanRow[];
}

interface PlanFormData {
    slug: string;
    name: string;
    is_active: boolean;
    max_agents: string;
    max_channels: string;
    max_tickets_per_month: string;
    ai_credits_per_month: string;
    [key: string]: string | boolean;
}

const text = (value: number | null) => (value === null ? '' : String(value));

function tenantsLabel(copy: PlansCopy, count: number): string {
    if (count === 0) return copy.tenants_none;

    return (count === 1 ? copy.tenants_one : copy.tenants_many).replace(':count', String(count));
}

interface PlanFormProps {
    copy: PlansCopy;
    plan: PlanRow | null;
}

/** Un solo formulario sirve para crear un plan y para editar uno: la clave solo se escribe al crear. */
function PlanForm({ copy, plan }: PlanFormProps) {
    const form = useForm<PlanFormData>({
        slug: '',
        name: plan?.name ?? '',
        is_active: plan?.is_active ?? true,
        max_agents: text(plan?.limits.agents ?? null),
        max_channels: text(plan?.limits.channels ?? null),
        max_tickets_per_month: text(plan?.limits.tickets_per_month ?? null),
        ai_credits_per_month: text(plan?.limits.ai_credits ?? null),
    });

    const submit = (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();

        // La clave solo se envía al crear: la de un plan existente no se puede cambiar.
        form.transform((data) => (plan === null ? data : { ...data, slug: undefined }));

        if (plan === null) {
            form.post('/platform/plans', {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                },
            });
        } else {
            form.put(`/platform/plans/${String(plan.id)}`, { preserveScroll: true });
        }
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <div className="grid gap-4 sm:grid-cols-2">
                {plan === null && (
                    <TextField
                        label={copy.slug}
                        value={form.data.slug}
                        error={form.errors.slug}
                        maxLength={30}
                        autoComplete="off"
                        onChange={(event) => {
                            form.setData('slug', event.target.value);
                        }}
                    />
                )}
                <TextField
                    label={copy.name}
                    value={form.data.name}
                    error={form.errors.name}
                    maxLength={60}
                    autoComplete="off"
                    onChange={(event) => {
                        form.setData('name', event.target.value);
                    }}
                />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                {limitKeys.map((key) => {
                    const field = limitFields[key];

                    return (
                        <TextField
                            key={key}
                            label={copy.labels[key]}
                            help={copy.limit_help}
                            type="number"
                            inputMode="numeric"
                            min={0}
                            max={1000000}
                            value={form.data[field]}
                            error={form.errors[field]}
                            onChange={(event) => {
                                form.setData(field, event.target.value);
                            }}
                        />
                    );
                })}
            </div>

            <Checkbox
                label={copy.active}
                checked={form.data.is_active}
                onChange={(event) => {
                    form.setData('is_active', event.target.checked);
                }}
            />
            {form.errors.is_active !== undefined && <Alert variant="danger">{form.errors.is_active}</Alert>}

            <div className="flex flex-wrap gap-3">
                <Button type="submit" loading={form.processing} loadingLabel={plan === null ? copy.creating : copy.saving}>
                    {plan === null ? copy.create : copy.save}
                </Button>
                {plan !== null && plan.is_active && !plan.is_default && (
                    <Button
                        variant="outline"
                        disabled={form.processing}
                        onClick={() => {
                            router.post(`/platform/plans/${String(plan.id)}/default`, {}, { preserveScroll: true });
                        }}
                    >
                        {copy.make_default}
                    </Button>
                )}
            </div>
        </form>
    );
}

export default function Plans({ copy, plans }: PlansProps) {
    const { props } = usePage<SharedProps>();
    const defaultError = props.errors.is_default;

    return (
        <PanelLayout title={copy.title} section="platform_plans" wide>
            {props.flash.status !== null && <Alert variant="success">{props.flash.status}</Alert>}
            {defaultError !== undefined && <Alert variant="danger">{defaultError}</Alert>}

            <p className="text-text-secondary">{copy.detail}</p>

            <ul className="flex flex-col gap-4">
                {plans.map((plan) => (
                    <li key={plan.id} className="flex flex-col gap-4 rounded-md border border-border bg-surface p-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="font-display text-lg font-semibold">{plan.name}</h2>
                            <code className="rounded-sm bg-surface-sunken px-2 py-0.5 font-mono text-sm text-text-secondary">{plan.slug}</code>
                            {plan.is_default && <Badge variant="primary">{copy.is_default}</Badge>}
                            {!plan.is_active && <Badge variant="neutral">{copy.inactive}</Badge>}
                            <span className="ml-auto text-sm text-text-secondary">{tenantsLabel(copy, plan.tenants)}</span>
                        </div>
                        <PlanForm copy={copy} plan={plan} />
                    </li>
                ))}
            </ul>

            <section aria-labelledby="new-plan" className="flex flex-col gap-4 rounded-md border border-border bg-surface p-4">
                <div className="flex flex-col gap-1">
                    <h2 id="new-plan" className="font-display text-lg font-semibold">
                        {copy.new_title}
                    </h2>
                    <p className="text-sm text-text-secondary">{copy.new_detail}</p>
                </div>
                <PlanForm copy={copy} plan={null} />
            </section>
        </PanelLayout>
    );
}
