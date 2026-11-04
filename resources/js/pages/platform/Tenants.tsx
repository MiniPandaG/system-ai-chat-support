import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { SubmitEvent } from 'react';
import { Alert } from '../../components/Alert';
import { Badge } from '../../components/Badge';
import { Button } from '../../components/Button';
import { ConfirmDialog } from '../../components/ConfirmDialog';
import { EmptyState } from '../../components/EmptyState';
import { BuildingIcon } from '../../components/icons';
import { SelectField } from '../../components/SelectField';
import { TextField } from '../../components/TextField';
import { PanelLayout } from '../../layouts/PanelLayout';
import type { SharedProps } from '../../types';

interface Usage {
    used: number;
    limit: number | null;
}

interface TenantRow {
    id: number;
    name: string;
    since_label: string | null;
    plan_id: number;
    plan_name: string;
    members: number;
    agents: Usage;
    tickets: Usage;
}

interface TenantsCopy {
    title: string;
    detail: string;
    search_label: string;
    search_placeholder: string;
    search_button: string;
    clear_search: string;
    table_label: string;
    name: string;
    plan: string;
    members: string;
    agents: string;
    tickets: string;
    since: string;
    unlimited: string;
    over: string;
    usage: string;
    change_plan: string;
    confirm_title: string;
    confirm_detail: string;
    confirm: string;
    confirming: string;
    cancel: string;
    empty: string;
    empty_search: string;
    page: string;
    previous: string;
    next: string;
}

interface TenantsProps {
    copy: TenantsCopy;
    tenants: TenantRow[];
    plans: { id: number; name: string }[];
    search: string;
    pagination: { page: number; last_page: number; total: number };
}

const number = new Intl.NumberFormat('es-CO');

interface PendingChange {
    tenant: TenantRow;
    planId: number;
}

export default function Tenants({ copy, tenants, plans, search, pagination }: TenantsProps) {
    const { props } = usePage<SharedProps>();
    const [query, setQuery] = useState(search);
    const [pending, setPending] = useState<PendingChange | null>(null);
    const [busy, setBusy] = useState(false);

    const planOptions = plans.map((plan) => ({ value: String(plan.id), label: plan.name }));
    const errors = Object.values(props.errors);
    const target = pending === null ? null : (plans.find((plan) => plan.id === pending.planId) ?? null);

    const usage = (item: Usage) => (item.limit === null ? `${number.format(item.used)} (${copy.unlimited})` : copy.usage.replace(':used', number.format(item.used)).replace(':max', number.format(item.limit)));

    const go = (page: number, text: string) => {
        const data: Record<string, string | number> = {};
        if (text !== '') data.q = text;
        if (page > 1) data.page = page;
        router.get('/platform/tenants', data, { preserveScroll: true });
    };

    const submit = (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        go(1, query.trim());
    };

    const confirm = () => {
        if (pending === null) return;

        setBusy(true);
        router.put(
            `/platform/tenants/${String(pending.tenant.id)}/plan`,
            { plan_id: pending.planId },
            {
                preserveScroll: true,
                onFinish: () => {
                    setBusy(false);
                    setPending(null);
                },
            },
        );
    };

    return (
        <PanelLayout title={copy.title} section="platform_tenants" wide>
            {props.flash.status !== null && <Alert variant="success">{props.flash.status}</Alert>}
            {errors.length > 0 && <Alert variant="danger">{errors.join(' ')}</Alert>}

            <p className="text-text-secondary">{copy.detail}</p>

            <form onSubmit={submit} role="search" className="flex flex-wrap items-end gap-3">
                <TextField
                    label={copy.search_label}
                    placeholder={copy.search_placeholder}
                    value={query}
                    maxLength={80}
                    className="min-w-[16rem] flex-1"
                    onChange={(event) => {
                        setQuery(event.target.value);
                    }}
                />
                <Button type="submit">{copy.search_button}</Button>
                {search !== '' && (
                    <Button
                        variant="text"
                        onClick={() => {
                            setQuery('');
                            go(1, '');
                        }}
                    >
                        {copy.clear_search}
                    </Button>
                )}
            </form>

            {tenants.length === 0 ? (
                <EmptyState icon={<BuildingIcon />} title={search === '' ? copy.empty : copy.empty_search} />
            ) : (
                <div className="overflow-x-auto rounded-md border border-border bg-surface">
                    <table aria-label={copy.table_label} className="w-full text-left text-base">
                        <thead className="border-b border-border text-sm text-text-secondary max-md:sr-only">
                            <tr>
                                {[copy.name, copy.plan, copy.members, copy.agents, copy.tickets, copy.since].map((heading) => (
                                    <th key={heading} scope="col" className="px-4 py-3 font-semibold">
                                        {heading}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {tenants.map((tenant) => (
                                <tr key={tenant.id} className="hover:bg-surface-hover max-md:flex max-md:flex-col max-md:gap-2 max-md:p-4">
                                    <td data-label={copy.name} className="px-4 py-3 font-medium max-md:flex max-md:justify-between max-md:p-0">
                                        {tenant.name}
                                    </td>
                                    <td data-label={copy.plan} className="px-4 py-3 max-md:flex max-md:items-center max-md:justify-between max-md:p-0">
                                        <SelectField
                                            label={copy.change_plan.replace(':company', tenant.name)}
                                            options={planOptions.some((option) => option.value === String(tenant.plan_id)) ? planOptions : [{ value: String(tenant.plan_id), label: tenant.plan_name }, ...planOptions]}
                                            value={String(tenant.plan_id)}
                                            disabled={busy}
                                            onChange={(event) => {
                                                const planId = Number(event.target.value);
                                                if (planId !== tenant.plan_id) setPending({ tenant, planId });
                                            }}
                                        />
                                    </td>
                                    <td data-label={copy.members} className="px-4 py-3 text-text-secondary max-md:flex max-md:justify-between max-md:p-0">
                                        {number.format(tenant.members)}
                                    </td>
                                    <td data-label={copy.agents} className="px-4 py-3 max-md:flex max-md:justify-between max-md:p-0">
                                        <span className="flex flex-wrap items-center gap-2">
                                            {usage(tenant.agents)}
                                            {tenant.agents.limit !== null && tenant.agents.used > tenant.agents.limit && <Badge variant="warning">{copy.over}</Badge>}
                                        </span>
                                    </td>
                                    <td data-label={copy.tickets} className="px-4 py-3 max-md:flex max-md:justify-between max-md:p-0">
                                        {usage(tenant.tickets)}
                                    </td>
                                    <td data-label={copy.since} className="px-4 py-3 text-text-secondary max-md:flex max-md:justify-between max-md:p-0">
                                        {tenant.since_label}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {pagination.last_page > 1 && (
                <nav aria-label={copy.table_label} className="flex items-center justify-between gap-3">
                    <Button
                        variant="outline"
                        disabled={pagination.page <= 1}
                        onClick={() => {
                            go(pagination.page - 1, search);
                        }}
                    >
                        {copy.previous}
                    </Button>
                    <p className="text-sm text-text-secondary">{copy.page.replace(':page', String(pagination.page)).replace(':last', String(pagination.last_page))}</p>
                    <Button
                        variant="outline"
                        disabled={pagination.page >= pagination.last_page}
                        onClick={() => {
                            go(pagination.page + 1, search);
                        }}
                    >
                        {copy.next}
                    </Button>
                </nav>
            )}

            <ConfirmDialog
                open={pending !== null && target !== null}
                title={pending === null ? '' : copy.confirm_title.replace(':company', pending.tenant.name)}
                detail={pending === null || target === null ? '' : copy.confirm_detail.replace(':company', pending.tenant.name).replace(':from', pending.tenant.plan_name).replace(':to', target.name)}
                cancelLabel={copy.cancel}
                confirmLabel={copy.confirm}
                confirmingLabel={copy.confirming}
                confirmVariant="primary"
                loading={busy}
                onConfirm={confirm}
                onCancel={() => {
                    setPending(null);
                }}
            />
        </PanelLayout>
    );
}
