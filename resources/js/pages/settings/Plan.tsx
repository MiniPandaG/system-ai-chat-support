import { Alert } from '../../components/Alert';
import { Badge } from '../../components/Badge';
import { UsageBar } from '../../components/UsageBar';
import { PanelLayout } from '../../layouts/PanelLayout';

type LimitKey = 'agents' | 'channels' | 'tickets_per_month' | 'ai_credits';

interface LimitItem {
    key: LimitKey;
    monthly: boolean;
    /** Si el recurso ya se mide en la cuenta; los demás llegan con su módulo. */
    measured: boolean;
    limit: number | null;
    used: number;
    remaining: number | null;
    over: boolean;
}

interface PlanCopy {
    title: string;
    current: string;
    detail: string;
    usage: string;
    unlimited: string;
    remaining: string;
    none_left: string;
    over: string;
    full: string;
    monthly: string;
    included: string;
    not_available: string;
    labels: Record<LimitKey, string>;
    help: Record<LimitKey, string>;
}

interface PlanProps {
    copy: PlanCopy;
    plan: { name: string };
    limits: LimitItem[];
    seatsDetail: string;
}

const number = new Intl.NumberFormat('es-CO');

export default function Plan({ copy, plan, limits, seatsDetail }: PlanProps) {
    return (
        <PanelLayout title={copy.title} section="plan">
            <div className="flex flex-col gap-2">
                <p className="flex flex-wrap items-center gap-2 text-text-secondary">
                    {copy.current} <Badge variant="primary">{plan.name}</Badge>
                </p>
                <p className="text-text-secondary">{copy.detail}</p>
            </div>

            <ul className="flex flex-col gap-4">
                {limits.map((item) => {
                    const label = copy.labels[item.key];
                    const max = item.limit;
                    const usage = max === null ? '' : copy.usage.replace(':used', number.format(item.used)).replace(':max', number.format(max));

                    return (
                        <li key={item.key} className="flex flex-col gap-3 rounded-md border border-border bg-surface p-4">
                            <div className="flex flex-wrap items-baseline justify-between gap-2">
                                <h2 className="font-display text-lg font-semibold">{label}</h2>
                                {max === null ? (
                                    <Badge variant="neutral">{copy.unlimited}</Badge>
                                ) : item.measured ? (
                                    <span className="text-base font-medium">{usage}</span>
                                ) : (
                                    <span className="text-base font-medium">{copy.included.replace(':max', number.format(max))}</span>
                                )}
                            </div>

                            {item.measured && max !== null && (
                                <>
                                    <UsageBar label={`${label}: ${usage}`} used={item.used} max={max} over={item.over} />
                                    <p className="text-sm text-text-secondary">
                                        {item.remaining === 0 ? copy.none_left : copy.remaining.replace(':remaining', number.format(item.remaining ?? 0))}
                                    </p>
                                </>
                            )}

                            {item.over && max !== null && <Alert variant="warning">{copy.over.replace(':used', number.format(item.used)).replace(':max', number.format(max))}</Alert>}
                            {item.measured && !item.over && max !== null && item.remaining === 0 && <Alert variant="info">{copy.full}</Alert>}

                            <p className="text-sm text-text-secondary">{copy.help[item.key]}</p>
                            {item.key === 'agents' && <p className="text-sm text-text-secondary">{seatsDetail}</p>}
                            {item.monthly && <p className="text-sm text-text-tertiary">{copy.monthly}</p>}
                            {!item.measured && <p className="text-sm text-text-tertiary">{copy.not_available}</p>}
                        </li>
                    );
                })}
            </ul>
        </PanelLayout>
    );
}
