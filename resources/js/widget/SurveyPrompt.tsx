import { useId, useState } from 'react';
import { Button } from '../components/Button';
import type { WidgetCopy } from './types';

interface SurveyPromptProps {
    copy: WidgetCopy;
    error: string | null;
    onAnswer: (score: number, comment: string) => Promise<boolean>;
    onDismiss: () => void;
}

const COMMENT_MAX = 1000;

/**
 * La encuesta de satisfacción dentro del widget (RF-36), para quien no dejó correo y no recibiría el enlace. Aparece cuando la
 * conversación terminó; la sesión del visitante identifica su ticket. Sin calificación no se envía; el comentario es opcional.
 */
export function SurveyPrompt({ copy, error, onAnswer, onDismiss }: SurveyPromptProps) {
    const id = useId();
    const commentId = `${id}-comment`;
    const [score, setScore] = useState<number | null>(null);
    const [comment, setComment] = useState('');
    const [saving, setSaving] = useState(false);
    const [missing, setMissing] = useState(false);
    const labels: Record<number, string> = { 1: copy.survey_scale_1, 2: copy.survey_scale_2, 3: copy.survey_scale_3, 4: copy.survey_scale_4, 5: copy.survey_scale_5 };

    return (
        <form
            noValidate
            className="flex flex-col gap-3 rounded-md border border-border bg-surface-sunken p-3"
            onSubmit={(event) => {
                event.preventDefault();

                if (score === null) {
                    setMissing(true);

                    return;
                }

                if (saving) return;

                setSaving(true);
                void onAnswer(score, comment.trim()).finally(() => {
                    setSaving(false);
                });
            }}
        >
            <p className="text-sm font-medium text-text">{copy.survey_title}</p>
            <p id={`${id}-help`} className="text-sm text-text-secondary">
                {copy.survey_help}
            </p>
            <fieldset aria-describedby={missing ? `${id}-help ${id}-missing` : `${id}-help`} className="flex flex-col gap-1">
                <legend className="sr-only">{copy.survey_score}</legend>
                <div className="flex gap-2">
                    {[1, 2, 3, 4, 5].map((value) => {
                        const inputId = `${id}-score-${String(value)}`;

                        return (
                        <div key={value} className="relative flex-1">
                            <input
                                id={inputId}
                                type="radio"
                                name={`${id}-score`}
                                value={value}
                                checked={score === value}
                                onChange={() => {
                                    setScore(value);
                                    setMissing(false);
                                }}
                                className="peer sr-only"
                            />
                            <label
                                htmlFor={inputId}
                                aria-label={`${String(value)} · ${labels[value] ?? ''}`}
                                className="flex min-h-11 min-w-11 cursor-pointer items-center justify-center rounded-md border border-border-strong bg-surface text-base font-semibold text-text hover:bg-surface-hover peer-checked:border-primary peer-checked:bg-primary peer-checked:text-primary-foreground peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-focus-ring"
                            >
                                {String(value)}
                            </label>
                        </div>
                        );
                    })}
                </div>
                {score !== null && <p className="text-sm text-text-secondary">{labels[score]}</p>}
                {missing && (
                    <p id={`${id}-missing`} role="alert" className="text-sm text-danger">
                        {copy.survey_score_required}
                    </p>
                )}
            </fieldset>
            <label htmlFor={commentId} className="text-sm font-medium text-text">
                {copy.survey_comment}
            </label>
            <textarea
                id={commentId}
                rows={3}
                maxLength={COMMENT_MAX}
                value={comment}
                onChange={(event) => {
                    setComment(event.target.value);
                }}
                className="w-full resize-y rounded-sm border border-border bg-surface px-3 py-2 font-body text-base text-text outline-none placeholder:text-text-tertiary hover:border-border-strong focus:border-primary"
            />
            {error !== null && (
                <p role="alert" className="text-sm text-danger">
                    {error === '' ? copy.survey_error : error}
                </p>
            )}
            <div className="flex gap-2">
                <Button type="submit" loading={saving} loadingLabel={copy.survey_sending}>
                    {copy.survey_send}
                </Button>
                <Button variant="text" onClick={onDismiss}>
                    {copy.survey_dismiss}
                </Button>
            </div>
        </form>
    );
}
