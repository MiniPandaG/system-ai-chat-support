import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Alert } from '../../components/Alert';
import { Badge } from '../../components/Badge';
import { Button } from '../../components/Button';
import { Checkbox } from '../../components/Checkbox';
import { Radio } from '../../components/Radio';
import { SelectField } from '../../components/SelectField';
import { TextAreaField } from '../../components/TextAreaField';
import { TextField } from '../../components/TextField';
import { UsageBar } from '../../components/UsageBar';
import { PanelLayout } from '../../layouts/PanelLayout';

type Mode = 'off' | 'shadow' | 'auto';
type Tone = 'friendly' | 'formal' | 'custom';
type ScheduleMode = 'always' | 'within' | 'outside';

interface AiCopy {
    title: string;
    intro: string;
    channels: string;
    channels_hint: string;
    widget: string;
    email: string;
    modes: Record<Mode, string>;
    auto_warning: string;
    model: string;
    model_label: string;
    model_hint: string;
    model_auto: string;
    summary_label: string;
    summary_hint: string;
    summary_same: string;
    model_none: string;
    tier_fast: string;
    tier_precise: string;
    cost: string;
    region: string;
    rules: string;
    require: string;
    require_hint: string;
    identify: string;
    identify_hint: string;
    show_sources: string;
    show_sources_hint: string;
    min_confidence: string;
    min_confidence_hint: string;
    max_messages: string;
    max_messages_hint: string;
    tone: string;
    tones: Record<Tone, string>;
    tone_custom: string;
    tone_custom_hint: string;
    topics: string;
    topics_hint: string;
    schedule: string;
    schedule_modes: Record<ScheduleMode, string>;
    schedule_hint: string;
    schedule_days: string;
    days: Record<string, string>;
    schedule_start: string;
    schedule_end: string;
    schedule_timezone: string;
    spend: string;
    spend_hint: string;
    daily_spend: string;
    monthly_spend: string;
    usage: string;
    usage_day: string;
    usage_month: string;
    usage_credits: string;
    usage_of: string;
    paused: string;
    privacy: string;
    save: string;
    saved: string;
}

interface AiSettingsData {
    reply_model: string | null;
    summary_model: string | null;
    widget_mode: Mode;
    email_mode: Mode;
    identify_as_ai: boolean;
    require_knowledge: boolean;
    min_confidence: number;
    show_sources: boolean;
    tone: Tone;
    tone_custom: string;
    schedule_mode: ScheduleMode;
    schedule_days: number[];
    schedule_start: string;
    schedule_end: string;
    schedule_timezone: string;
    escalate_topics: string[];
    max_messages: number;
    daily_spend: string;
    monthly_spend: string;
}

interface ModelOption {
    id: string;
    label: string;
    tier: string;
    provider: string;
    region: string;
    cost: string;
}

interface Meter {
    spent: string;
    limit: string;
    percent: number;
}

interface AiProps {
    copy: AiCopy;
    settings: AiSettingsData;
    defaults: { daily_spend: string; monthly_spend: string; max_daily_spend: string; max_monthly_spend: string; min_confidence: number; max_topics: number; topic_length: number; tone_length: number };
    timezones: string[];
    models: ModelOption[];
    usage: { paused: string | null; day: Meter; month: Meter; credits: { used: number; limit: number; percent: number } | null };
}

const channelModes: Mode[] = ['off', 'shadow', 'auto'];
const tones: Tone[] = ['friendly', 'formal', 'custom'];
const scheduleModes: ScheduleMode[] = ['always', 'within', 'outside'];

/**
 * Cómo participa la IA en la empresa: qué hace en cada canal, con qué modelos y con qué reglas, y cuánto lleva gastado
 * frente a sus topes. Todo lo valida el servidor; aquí solo se eligen opciones de listas cerradas y se muestra el costo
 * estimado, dónde procesa cada modelo y el consumo.
 */
export default function Ai({ copy, settings, defaults, timezones, models, usage }: AiProps) {
    const form = useForm<AiSettingsData>({ ...settings });
    const [topicsText, setTopicsText] = useState(settings.escalate_topics.join('\n'));
    const chosen = models.find((model) => model.id === form.data.reply_model) ?? null;
    const summaryChosen = models.find((model) => model.id === form.data.summary_model) ?? null;
    const anyAuto = form.data.widget_mode === 'auto' || form.data.email_mode === 'auto';
    const scheduled = form.data.schedule_mode !== 'always';

    const error = (field: string): string | undefined => (form.errors as Record<string, string | undefined>)[field];

    const modelOptions = (first: string) => [{ value: '', label: first }, ...models.map((model) => ({ value: model.id, label: `${model.label} · ${model.provider}` }))];

    const describe = (model: ModelOption | null) =>
        model === null ? null : (
            <p className="flex flex-wrap items-center gap-2 text-sm text-text-secondary">
                <Badge variant={model.tier === 'precise' ? 'primary' : 'neutral'}>{model.tier === 'precise' ? copy.tier_precise : copy.tier_fast}</Badge>
                <span>{copy.cost.replace(':cost', model.cost)}</span>
                <span>{copy.region.replace(':region', model.region)}</span>
            </p>
        );

    const modeGroup = (field: 'widget_mode' | 'email_mode', label: string) => (
        <fieldset className="flex flex-col">
            <legend className="mb-1 text-sm font-medium text-text">{label}</legend>
            {channelModes.map((mode) => (
                <Radio
                    key={mode}
                    name={field}
                    label={copy.modes[mode]}
                    checked={form.data[field] === mode}
                    onChange={() => {
                        form.setData(field, mode);
                    }}
                />
            ))}
            {error(field) !== undefined && <p className="text-sm text-danger">{error(field)}</p>}
        </fieldset>
    );

    const toggleDay = (day: number, on: boolean) => {
        const days = on ? [...form.data.schedule_days, day] : form.data.schedule_days.filter((current) => current !== day);
        form.setData('schedule_days', [...new Set(days)].sort((a, b) => a - b));
    };

    return (
        <PanelLayout title={copy.title} section="ai">
            <p className="text-text-secondary">{copy.intro}</p>

            <section className="flex flex-col gap-3 rounded-md border border-border bg-surface p-4" aria-labelledby="ai-usage">
                <h2 id="ai-usage" className="font-display text-lg font-semibold">
                    {copy.usage}
                </h2>
                {usage.paused !== null && <Alert variant="warning">{copy.paused.replace(':reason', usage.paused)}</Alert>}
                {[
                    { label: copy.usage_day, meter: usage.day },
                    { label: copy.usage_month, meter: usage.month },
                ].map(({ label, meter }) => (
                    <div key={label} className="flex flex-col gap-1">
                        <p className="text-sm text-text-secondary">
                            {label}: {copy.usage_of.replace(':used', meter.spent).replace(':limit', meter.limit)}
                        </p>
                        <UsageBar label={`${label}: ${copy.usage_of.replace(':used', meter.spent).replace(':limit', meter.limit)}`} used={meter.percent} max={100} over={meter.percent >= 100} />
                    </div>
                ))}
                {usage.credits !== null && (
                    <div className="flex flex-col gap-1">
                        <p className="text-sm text-text-secondary">
                            {copy.usage_credits}: {copy.usage_of.replace(':used', String(usage.credits.used)).replace(':limit', String(usage.credits.limit))}
                        </p>
                        <UsageBar
                            label={`${copy.usage_credits}: ${copy.usage_of.replace(':used', String(usage.credits.used)).replace(':limit', String(usage.credits.limit))}`}
                            used={usage.credits.used}
                            max={usage.credits.limit}
                            over={usage.credits.used >= usage.credits.limit}
                        />
                    </div>
                )}
            </section>

            <form
                noValidate
                className="flex flex-col gap-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put('/settings/ai', { preserveScroll: true });
                }}
            >
                <section className="flex flex-col gap-4 rounded-md border border-border bg-surface p-4" aria-labelledby="ai-channels">
                    <div className="flex flex-col gap-1">
                        <h2 id="ai-channels" className="font-display text-lg font-semibold">
                            {copy.channels}
                        </h2>
                        <p className="text-sm text-text-secondary">{copy.channels_hint}</p>
                    </div>
                    {modeGroup('widget_mode', copy.widget)}
                    {modeGroup('email_mode', copy.email)}
                    {anyAuto && <Alert variant="warning">{copy.auto_warning}</Alert>}
                </section>

                <section className="flex flex-col gap-3 rounded-md border border-border bg-surface p-4" aria-labelledby="ai-model">
                    <div className="flex flex-col gap-1">
                        <h2 id="ai-model" className="font-display text-lg font-semibold">
                            {copy.model}
                        </h2>
                        <p className="text-sm text-text-secondary">{copy.model_hint}</p>
                    </div>
                    {models.length === 0 ? (
                        <Alert variant="warning">{copy.model_none}</Alert>
                    ) : (
                        <>
                            <SelectField
                                label={copy.model_label}
                                visibleLabel
                                value={form.data.reply_model ?? ''}
                                options={modelOptions(copy.model_auto)}
                                onChange={(event) => {
                                    form.setData('reply_model', event.target.value === '' ? null : event.target.value);
                                }}
                            />
                            {error('reply_model') !== undefined && <p className="text-sm text-danger">{error('reply_model')}</p>}
                            {describe(chosen)}
                            <SelectField
                                label={copy.summary_label}
                                visibleLabel
                                value={form.data.summary_model ?? ''}
                                options={modelOptions(copy.summary_same)}
                                onChange={(event) => {
                                    form.setData('summary_model', event.target.value === '' ? null : event.target.value);
                                }}
                            />
                            <p className="text-sm text-text-secondary">{copy.summary_hint}</p>
                            {error('summary_model') !== undefined && <p className="text-sm text-danger">{error('summary_model')}</p>}
                            {describe(summaryChosen)}
                        </>
                    )}
                </section>

                <section className="flex flex-col gap-4 rounded-md border border-border bg-surface p-4" aria-labelledby="ai-rules">
                    <h2 id="ai-rules" className="font-display text-lg font-semibold">
                        {copy.rules}
                    </h2>

                    <div className="flex flex-col gap-1">
                        <Checkbox
                            label={copy.require}
                            checked={form.data.require_knowledge}
                            onChange={(event) => {
                                form.setData('require_knowledge', event.target.checked);
                            }}
                        />
                        <p className="text-sm text-text-secondary">{copy.require_hint}</p>
                    </div>
                    <div className="flex flex-col gap-1">
                        <Checkbox
                            label={copy.show_sources}
                            checked={form.data.show_sources}
                            onChange={(event) => {
                                form.setData('show_sources', event.target.checked);
                            }}
                        />
                        <p className="text-sm text-text-secondary">{copy.show_sources_hint}</p>
                    </div>
                    <div className="flex flex-col gap-1">
                        <Checkbox
                            label={copy.identify}
                            checked={form.data.identify_as_ai}
                            onChange={(event) => {
                                form.setData('identify_as_ai', event.target.checked);
                            }}
                        />
                        <p className="text-sm text-text-secondary">{copy.identify_hint}</p>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            label={copy.min_confidence}
                            help={copy.min_confidence_hint.replace(':min', String(defaults.min_confidence))}
                            type="number"
                            inputMode="numeric"
                            min={defaults.min_confidence}
                            max={100}
                            value={String(form.data.min_confidence)}
                            error={error('min_confidence')}
                            onChange={(event) => {
                                form.setData('min_confidence', event.target.value === '' ? 0 : Number(event.target.value));
                            }}
                        />
                        <TextField
                            label={copy.max_messages}
                            help={copy.max_messages_hint}
                            type="number"
                            inputMode="numeric"
                            min={1}
                            max={20}
                            value={String(form.data.max_messages)}
                            error={error('max_messages')}
                            onChange={(event) => {
                                form.setData('max_messages', event.target.value === '' ? 0 : Number(event.target.value));
                            }}
                        />
                    </div>

                    <fieldset className="flex flex-col">
                        <legend className="mb-1 text-sm font-medium text-text">{copy.tone}</legend>
                        {tones.map((tone) => (
                            <Radio
                                key={tone}
                                name="tone"
                                label={copy.tones[tone]}
                                checked={form.data.tone === tone}
                                onChange={() => {
                                    form.setData('tone', tone);
                                }}
                            />
                        ))}
                        {error('tone') !== undefined && <p className="text-sm text-danger">{error('tone')}</p>}
                    </fieldset>
                    {form.data.tone === 'custom' && (
                        <TextAreaField
                            label={copy.tone_custom}
                            help={copy.tone_custom_hint.replace(':max', String(defaults.tone_length))}
                            value={form.data.tone_custom}
                            maxLength={defaults.tone_length}
                            error={error('tone_custom')}
                            onChange={(event) => {
                                form.setData('tone_custom', event.target.value);
                            }}
                        />
                    )}

                    <TextAreaField
                        label={copy.topics}
                        help={copy.topics_hint.replace(':max', String(defaults.max_topics))}
                        value={topicsText}
                        error={error('escalate_topics') ?? Object.entries(form.errors).find(([key]) => key.startsWith('escalate_topics.'))?.[1]}
                        onChange={(event) => {
                            setTopicsText(event.target.value);
                            form.setData(
                                'escalate_topics',
                                event.target.value
                                    .split('\n')
                                    .map((line) => line.trim())
                                    .filter((line) => line !== ''),
                            );
                        }}
                    />
                </section>

                <section className="flex flex-col gap-4 rounded-md border border-border bg-surface p-4" aria-labelledby="ai-schedule">
                    <div className="flex flex-col gap-1">
                        <h2 id="ai-schedule" className="font-display text-lg font-semibold">
                            {copy.schedule}
                        </h2>
                        <p className="text-sm text-text-secondary">{copy.schedule_hint}</p>
                    </div>
                    <fieldset className="flex flex-col">
                        <legend className="sr-only">{copy.schedule}</legend>
                        {scheduleModes.map((mode) => (
                            <Radio
                                key={mode}
                                name="schedule_mode"
                                label={copy.schedule_modes[mode]}
                                checked={form.data.schedule_mode === mode}
                                onChange={() => {
                                    form.setData('schedule_mode', mode);
                                }}
                            />
                        ))}
                        {error('schedule_mode') !== undefined && <p className="text-sm text-danger">{error('schedule_mode')}</p>}
                    </fieldset>
                    {scheduled && (
                        <>
                            <fieldset className="flex flex-col gap-1">
                                <legend className="mb-1 text-sm font-medium text-text">{copy.schedule_days}</legend>
                                <div className="flex flex-wrap gap-x-4 gap-y-1">
                                    {[1, 2, 3, 4, 5, 6, 7].map((day) => (
                                        <Checkbox
                                            key={day}
                                            label={copy.days[String(day)] ?? String(day)}
                                            checked={form.data.schedule_days.includes(day)}
                                            onChange={(event) => {
                                                toggleDay(day, event.target.checked);
                                            }}
                                        />
                                    ))}
                                </div>
                                {error('schedule_days') !== undefined && <p className="text-sm text-danger">{error('schedule_days')}</p>}
                            </fieldset>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <TextField
                                    label={copy.schedule_start}
                                    type="time"
                                    value={form.data.schedule_start}
                                    error={error('schedule_start')}
                                    onChange={(event) => {
                                        form.setData('schedule_start', event.target.value);
                                    }}
                                />
                                <TextField
                                    label={copy.schedule_end}
                                    type="time"
                                    value={form.data.schedule_end}
                                    error={error('schedule_end')}
                                    onChange={(event) => {
                                        form.setData('schedule_end', event.target.value);
                                    }}
                                />
                            </div>
                            <SelectField
                                label={copy.schedule_timezone}
                                visibleLabel
                                value={form.data.schedule_timezone}
                                options={timezones.map((zone) => ({ value: zone, label: zone.replace('_', ' ') }))}
                                onChange={(event) => {
                                    form.setData('schedule_timezone', event.target.value);
                                }}
                            />
                            {error('schedule_timezone') !== undefined && <p className="text-sm text-danger">{error('schedule_timezone')}</p>}
                        </>
                    )}
                </section>

                <section className="flex flex-col gap-4 rounded-md border border-border bg-surface p-4" aria-labelledby="ai-spend">
                    <div className="flex flex-col gap-1">
                        <h2 id="ai-spend" className="font-display text-lg font-semibold">
                            {copy.spend}
                        </h2>
                        <p className="text-sm text-text-secondary">{copy.spend_hint.replace(':daily', defaults.daily_spend).replace(':monthly', defaults.monthly_spend)}</p>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <TextField
                            label={copy.daily_spend}
                            type="number"
                            inputMode="decimal"
                            min={0.01}
                            max={defaults.max_daily_spend}
                            step="0.01"
                            placeholder={defaults.daily_spend}
                            value={form.data.daily_spend}
                            error={error('daily_spend')}
                            onChange={(event) => {
                                form.setData('daily_spend', event.target.value);
                            }}
                        />
                        <TextField
                            label={copy.monthly_spend}
                            type="number"
                            inputMode="decimal"
                            min={0.01}
                            max={defaults.max_monthly_spend}
                            step="0.01"
                            placeholder={defaults.monthly_spend}
                            value={form.data.monthly_spend}
                            error={error('monthly_spend')}
                            onChange={(event) => {
                                form.setData('monthly_spend', event.target.value);
                            }}
                        />
                    </div>
                </section>

                <p className="text-sm text-text-secondary">{copy.privacy}</p>

                <div>
                    <Button type="submit" loading={form.processing} loadingLabel={copy.save} disabled={!form.isDirty}>
                        {copy.save}
                    </Button>
                </div>
            </form>
        </PanelLayout>
    );
}
