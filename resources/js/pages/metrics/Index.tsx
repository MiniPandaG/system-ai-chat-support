import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { EmptyState } from '../../components/EmptyState';
import { ChartIcon, FrownIcon, MehIcon, SmileIcon } from '../../components/icons';
import type { ChangeCopy, MetricChange } from '../../components/MetricCard';
import { MetricCard } from '../../components/MetricCard';
import { SelectField } from '../../components/SelectField';
import { TextField } from '../../components/TextField';
import { TrendChart } from '../../components/TrendChart';
import { PanelLayout } from '../../layouts/PanelLayout';

type Preset = 'today' | '7d' | '30d' | 'custom';

interface Card {
    value: number | null;
    previous: number | null;
    delta: number | null;
    unit: 'percent' | 'points';
    better: 'up' | 'down';
}

interface MetricsCopy {
    title: string;
    range_label: string;
    presets: Record<Preset, string>;
    from: string;
    to: string;
    apply: string;
    period: string;
    previous_hint: string;
    sampled: string;
    no_data: string;
    no_data_detail: string;
    cards: Record<string, string>;
    change: ChangeCopy;
    csat: {
        title: string;
        average: string;
        responses: string;
        distribution: string;
        scale: Record<string, string>;
        level: Record<string, string>;
        comments: string;
        filter: string;
        all: string;
        score_option: string;
        ticket: string;
        no_comments: string;
        more: string;
        col_score: string;
        col_comment: string;
        col_ticket: string;
        col_date: string;
    };
    trend: Record<string, string>;
}

interface Report {
    range: { from: string; to: string; days: number };
    open_now: number;
    cards: {
        received: Card;
        resolved: Card;
        first_response: Card & { label: string; sampled: boolean };
        resolution: Card & { label: string; sampled: boolean };
        csat: Card & { count: number };
        ai_resolved: Card & { resolved: number; by_ai: number };
    };
    csat: { sent: number; distribution: Record<string, number> };
    ai: { spent_micro: number; spent: string; calls: number; credits: number };
    trend: { date: string; received: number; resolved: number; first_response: number | null }[];
}

interface Comment {
    id: number;
    score: number;
    comment: string;
    answered_label: string;
    ticket: { id: number; number: number; subject: string } | null;
}

interface MetricsProps {
    copy: MetricsCopy;
    range_error: string | null;
    filters: { range: Preset; from: string; to: string; score: number | null };
    report: Report;
    comments: { data: Comment[]; next_url: string | null };
}

const presets: Preset[] = ['today', '7d', '30d', 'custom'];
const decimal = new Intl.NumberFormat('es-CO', { minimumFractionDigits: 1, maximumFractionDigits: 1 });

function change(card: Card): MetricChange {
    return { delta: card.delta, unit: card.unit, better: card.better };
}

function SatisfactionIcon({ average, label }: { average: number | null; label: string }) {
    if (average === null) return null;

    const Icon = average < 2.5 ? FrownIcon : average < 4 ? MehIcon : SmileIcon;

    return <Icon aria-hidden={false} aria-label={label} role="img" className="size-7 text-text-secondary" />;
}

/**
 * Las métricas del soporte y la satisfacción (RF-37, HU-12; design/pantallas/metricas-csat.md). Todo viene calculado del
 * servidor: aquí solo se elige el rango y se dice cada cifra con su cambio, con palabras además de color. Los comentarios de los
 * clientes se muestran como texto.
 */
export default function Index({ copy, range_error, filters, report, comments }: MetricsProps) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);
    const [custom, setCustom] = useState(filters.range === 'custom');
    const { cards } = report;
    const empty = cards.received.value === 0 && cards.resolved.value === 0 && cards.csat.count === 0;
    const csat = copy.csat;

    const go = (params: Record<string, string | number | null>) => {
        const query = Object.fromEntries(Object.entries(params).filter(([, value]) => value !== null && value !== ''));
        router.get('/metrics', query, { preserveScroll: true, preserveState: true });
    };

    const scoreLabel = (score: number): string => csat.score_option.replace(':score', String(score)).replace(':label', csat.scale[String(score)] ?? '');
    const duration = (seconds: number): string => `${decimal.format(seconds / 60)} min`;
    const sampled = cards.first_response.sampled || cards.resolution.sampled;
    const maxVotes = Math.max(1, ...Object.values(report.csat.distribution));

    return (
        <PanelLayout title={copy.title} section="metrics" wide>
            <div className="flex flex-col gap-3">
                <fieldset className="flex flex-wrap items-center gap-2">
                    <legend className="mb-1 text-sm font-medium text-text">{copy.range_label}</legend>
                    {presets.map((preset) => (
                        <Button
                            key={preset}
                            variant={filters.range === preset ? 'primary' : 'outline'}
                            aria-pressed={filters.range === preset}
                            onClick={() => {
                                if (preset === 'custom') {
                                    setCustom(true);

                                    return;
                                }

                                setCustom(false);
                                go({ range: preset });
                            }}
                        >
                            {copy.presets[preset]}
                        </Button>
                    ))}
                </fieldset>
                {custom && (
                    <form
                        noValidate
                        className="flex flex-wrap items-end gap-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            go({ range: 'custom', from, to });
                        }}
                    >
                        <TextField label={copy.from} type="date" value={from} max={report.range.to} onChange={(event) => { setFrom(event.target.value); }} />
                        <TextField label={copy.to} type="date" value={to} max={report.range.to} onChange={(event) => { setTo(event.target.value); }} />
                        <Button type="submit">{copy.apply}</Button>
                    </form>
                )}
                {range_error !== null && <Alert variant="warning">{range_error}</Alert>}
                <p className="text-sm text-text-secondary">
                    {copy.period.replace(':from', report.range.from).replace(':to', report.range.to)} · {copy.previous_hint.replace(':days', String(report.range.days))}
                </p>
                {sampled && <Alert variant="info">{copy.sampled}</Alert>}
            </div>

            {empty && (
                <EmptyState icon={<ChartIcon />} title={copy.no_data} detail={copy.no_data_detail} />
            )}

            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <MetricCard title={copy.cards.open_now ?? ''} value={String(report.open_now)} hint={copy.cards.open_now_hint ?? ''} copy={copy.change} />
                <MetricCard title={copy.cards.received ?? ''} value={String(cards.received.value ?? 0)} change={change(cards.received)} copy={copy.change} />
                <MetricCard title={copy.cards.resolved ?? ''} value={String(cards.resolved.value ?? 0)} change={change(cards.resolved)} copy={copy.change} />
                <MetricCard title={copy.cards.first_response ?? ''} value={cards.first_response.label} hint={copy.cards.first_response_hint ?? ''} change={change(cards.first_response)} copy={copy.change} />
                <MetricCard title={copy.cards.resolution ?? ''} value={cards.resolution.label} hint={copy.cards.resolution_hint ?? ''} change={change(cards.resolution)} copy={copy.change} />
                <MetricCard
                    title={copy.cards.csat ?? ''}
                    value={cards.csat.value === null ? '—' : `${decimal.format(cards.csat.value)} / 5`}
                    hint={cards.csat.count === 0 ? (copy.cards.csat_none ?? '') : (copy.cards.csat_hint ?? '').replace(':count', String(cards.csat.count))}
                    icon={<SatisfactionIcon average={cards.csat.value} label={cards.csat.value === null ? '' : (csat.level[cards.csat.value < 2.5 ? 'low' : cards.csat.value < 4 ? 'mid' : 'high'] ?? '')} />}
                    change={change(cards.csat)}
                    copy={copy.change}
                />
                <MetricCard
                    title={copy.cards.ai_resolved ?? ''}
                    value={cards.ai_resolved.value === null ? '—' : `${String(cards.ai_resolved.value)} %`}
                    hint={(copy.cards.ai_resolved_hint ?? '').replace(':by', String(cards.ai_resolved.by_ai)).replace(':total', String(cards.ai_resolved.resolved))}
                    change={change(cards.ai_resolved)}
                    pointsAsPp
                    copy={copy.change}
                />
                <MetricCard title={copy.cards.ai_cost ?? ''} value={report.ai.spent} hint={(copy.cards.ai_cost_hint ?? '').replace(':calls', String(report.ai.calls)).replace(':credits', String(report.ai.credits))} copy={copy.change} />
            </div>

            <section aria-labelledby="metrics-trend" className="flex flex-col gap-4">
                <h2 id="metrics-trend" className="font-display text-lg font-semibold">
                    {copy.trend.title}
                </h2>
                <div className="grid gap-4 lg:grid-cols-2">
                    <TrendChart
                        title={copy.trend.volume ?? ''}
                        points={report.trend.map((day) => ({ date: day.date, value: day.received }))}
                        from={report.range.from}
                        to={report.range.to}
                        format={(value) => String(value)}
                        labels={{ chart: copy.trend.chart ?? '', table: copy.trend.table ?? '', chartLabel: copy.trend.chart_label ?? '', colDate: copy.trend.col_date ?? '', colValue: copy.trend.col_received ?? '' }}
                    />
                    <TrendChart
                        title={copy.trend.response ?? ''}
                        points={report.trend.map((day) => ({ date: day.date, value: day.first_response === null ? null : day.first_response / 60 }))}
                        from={report.range.from}
                        to={report.range.to}
                        format={(value) => duration(value * 60)}
                        labels={{ chart: copy.trend.chart ?? '', table: copy.trend.table ?? '', chartLabel: copy.trend.chart_label ?? '', colDate: copy.trend.col_date ?? '', colValue: copy.trend.col_response ?? '' }}
                    />
                </div>
            </section>

            <section aria-labelledby="metrics-csat" className="flex flex-col gap-4">
                <h2 id="metrics-csat" className="font-display text-lg font-semibold">
                    {csat.title}
                </h2>
                <div className="flex flex-col gap-4 rounded-md border border-border bg-surface-raised p-5">
                    <p className="flex items-center gap-2 text-text">
                        <SatisfactionIcon average={cards.csat.value} label={cards.csat.value === null ? '' : (csat.level[cards.csat.value < 2.5 ? 'low' : cards.csat.value < 4 ? 'mid' : 'high'] ?? '')} />
                        <span className="font-display text-xl font-semibold">{cards.csat.value === null ? '—' : csat.average.replace(':average', decimal.format(cards.csat.value))}</span>
                    </p>
                    <p className="text-sm text-text-secondary">{csat.responses.replace(':count', String(cards.csat.count)).replace(':sent', String(report.csat.sent))}</p>
                    <div className="flex flex-col gap-2">
                        <h3 className="text-sm font-medium text-text">{csat.distribution}</h3>
                        <ul className="flex flex-col gap-2">
                            {[5, 4, 3, 2, 1].map((score) => {
                                const count = report.csat.distribution[String(score)] ?? 0;

                                return (
                                    <li key={score} className="grid grid-cols-[8rem_1fr_2.5rem] items-center gap-3 text-sm">
                                        <span className="text-text-secondary">{scoreLabel(score)}</span>
                                        <span aria-hidden="true" className="h-3 overflow-hidden rounded-full bg-border">
                                            <span className="block h-full rounded-full bg-primary" style={{ width: `${String(Math.round((count / maxVotes) * 100))}%` }} />
                                        </span>
                                        <span className="text-right font-medium text-text">{count}</span>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                </div>

                <div className="flex flex-col gap-3">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <h3 className="font-display text-base font-semibold">{csat.comments}</h3>
                        <SelectField
                            label={csat.filter}
                            visibleLabel
                            value={filters.score === null ? '' : String(filters.score)}
                            options={[{ value: '', label: csat.all }, ...[5, 4, 3, 2, 1].map((score) => ({ value: String(score), label: scoreLabel(score) }))]}
                            onChange={(event) => {
                                go({ range: filters.range, from: filters.range === 'custom' ? filters.from : null, to: filters.range === 'custom' ? filters.to : null, score: event.target.value === '' ? null : event.target.value });
                            }}
                        />
                    </div>
                    {comments.data.length === 0 ? (
                        <p className="rounded-md border border-border bg-surface p-4 text-text-secondary">{csat.no_comments}</p>
                    ) : (
                        <div className="overflow-hidden rounded-md border border-border bg-surface">
                            <table className="w-full text-left text-base">
                                <caption className="sr-only">{csat.comments}</caption>
                                <thead className="hidden bg-surface text-sm text-text-secondary md:table-header-group">
                                    <tr>
                                        <th scope="col" className="px-4 py-3 font-semibold">{csat.col_score}</th>
                                        <th scope="col" className="px-4 py-3 font-semibold">{csat.col_comment}</th>
                                        <th scope="col" className="px-4 py-3 font-semibold">{csat.col_ticket}</th>
                                        <th scope="col" className="px-4 py-3 font-semibold">{csat.col_date}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {comments.data.map((row) => (
                                        <tr key={row.id} className="block border-t border-border first:border-t-0 md:table-row">
                                            <td className="block px-4 pt-3 font-medium text-text md:table-cell md:py-3">{scoreLabel(row.score)}</td>
                                            <td className="block whitespace-pre-wrap break-words px-4 py-1 md:table-cell md:py-3">{row.comment}</td>
                                            <td className="block px-4 py-1 md:table-cell md:py-3">
                                                {row.ticket === null ? null : (
                                                    <Link href={`/tickets/${String(row.ticket.id)}`} className="underline hover:text-text" title={row.ticket.subject}>
                                                        {csat.ticket.replace(':number', String(row.ticket.number))}
                                                    </Link>
                                                )}
                                            </td>
                                            <td className="block px-4 pb-3 text-sm text-text-secondary md:table-cell md:py-3">{row.answered_label}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    {comments.next_url !== null && (
                        <div>
                            <Link href={comments.next_url} preserveScroll className="text-sm font-medium underline hover:text-text">
                                {csat.more}
                            </Link>
                        </div>
                    )}
                </div>
            </section>
        </PanelLayout>
    );
}
