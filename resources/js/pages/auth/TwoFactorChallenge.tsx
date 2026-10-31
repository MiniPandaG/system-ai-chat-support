import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { SubmitEvent } from 'react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { OtpInput } from '../../components/OtpInput';
import { TextField } from '../../components/TextField';
import { AuthLayout } from '../../layouts/AuthLayout';

interface ChallengeCopy {
    title: string;
    detail: string;
    code_label: string;
    digit: string;
    submit: string;
    submitting: string;
    use_recovery: string;
    use_app: string;
    recovery_detail: string;
    recovery_label: string;
    recovery_submit: string;
    back: string;
    incomplete: string;
    required: string;
}

type Mode = 'app' | 'recovery';

export default function TwoFactorChallenge({ copy }: { copy: ChallengeCopy }) {
    const [mode, setMode] = useState<Mode>('app');
    const [attempt, setAttempt] = useState(0);
    const [localError, setLocalError] = useState('');
    const form = useForm({ code: '', recovery_code: '' });
    const recoveryRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        if (mode === 'recovery') {
            recoveryRef.current?.focus();
        }
    }, [mode, attempt]);

    const send = (payload: { code: string } | { recovery_code: string }) => {
        form.transform(() => payload);
        form.post('/two-factor-challenge', {
            onError: () => {
                // Tras un fallo las casillas se vacían y el foco vuelve a la primera.
                form.setData({ code: '', recovery_code: '' });
                setAttempt((current) => current + 1);
            },
        });
    };

    const submit = (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();
        setLocalError('');

        if (mode === 'app') {
            if (form.data.code.length !== 6) {
                setLocalError(copy.incomplete);

                return;
            }
            send({ code: form.data.code });
        } else {
            if (form.data.recovery_code.trim() === '') {
                setLocalError(copy.required);

                return;
            }
            send({ recovery_code: form.data.recovery_code });
        }
    };

    const serverError = form.errors.code ?? form.errors.recovery_code;
    const switchMode = (next: Mode) => {
        setMode(next);
        setLocalError('');
        form.clearErrors();
        form.setData({ code: '', recovery_code: '' });
        setAttempt((current) => current + 1);
    };

    return (
        <AuthLayout title={copy.title}>
            <p className="text-text-secondary">{mode === 'app' ? copy.detail : copy.recovery_detail}</p>

            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                {serverError !== undefined && <Alert variant="danger">{serverError}</Alert>}

                {mode === 'app' ? (
                    <OtpInput
                        key={`app-${String(attempt)}`}
                        label={copy.code_label}
                        digitLabel={(position, total) => copy.digit.replace(':number', String(position)).replace(':total', String(total))}
                        error={localError === '' ? undefined : localError}
                        disabled={form.processing}
                        focusOnMount
                        onChange={(code) => {
                            form.setData('code', code);
                        }}
                        onComplete={(code) => {
                            send({ code });
                        }}
                    />
                ) : (
                    <TextField
                        key={`recovery-${String(attempt)}`}
                        label={copy.recovery_label}
                        name="recovery_code"
                        autoComplete="off"
                        autoCapitalize="characters"
                        spellCheck={false}
                        inputRef={recoveryRef}
                        className="font-mono"
                        error={localError === '' ? undefined : localError}
                        onChange={(event) => {
                            form.setData('recovery_code', event.target.value);
                        }}
                    />
                )}

                <Button type="submit" size="lg" fullWidth loading={form.processing} loadingLabel={copy.submitting}>
                    {mode === 'app' ? copy.submit : copy.recovery_submit}
                </Button>
            </form>

            <button
                type="button"
                onClick={() => {
                    switchMode(mode === 'app' ? 'recovery' : 'app');
                }}
                className="self-start text-sm text-text-secondary underline hover:text-text"
            >
                {mode === 'app' ? copy.use_recovery : copy.use_app}
            </button>
            <Link href="/login" className="self-start text-sm text-text-secondary underline hover:text-text">
                {copy.back}
            </Link>
        </AuthLayout>
    );
}
