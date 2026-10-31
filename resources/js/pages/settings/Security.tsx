import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { Checkbox } from '../../components/Checkbox';
import { OtpInput } from '../../components/OtpInput';
import { PasswordChangeForm } from '../../components/PasswordChangeForm';
import type { PasswordChangeCopy } from '../../components/PasswordChangeForm';
import { SessionsList } from '../../components/SessionsList';
import type { SessionItem, SessionsCopy } from '../../components/SessionsList';
import { PanelLayout } from '../../layouts/PanelLayout';
import type { SharedProps } from '../../types';

interface SecurityCopy {
    title: string;
    back: string;
    two_factor: {
        title: string;
        detail: string;
        required_notice: string;
        status_off: string;
        status_on: string;
        enable: string;
        enabling: string;
        disable: string;
        disabling: string;
        cancel: string;
        setup_title: string;
        setup_detail: string;
        qr_alt: string;
        manual_key: string;
        code_label: string;
        confirm: string;
        confirming: string;
        invalid_code: string;
        enabled_detail: string;
        codes_left: string;
        codes_left_one: string;
        codes_low: string;
        codes_low_one: string;
        codes_none: string;
    };
    password: PasswordChangeCopy;
    sessions: SessionsCopy;
    recovery: {
        title: string;
        detail: string;
        warning: string;
        copy_all: string;
        copied: string;
        download: string;
        file_name: string;
        saved: string;
        continue: string;
        regenerate: string;
        regenerating: string;
        regenerate_detail: string;
    };
}

interface SecurityProps {
    copy: SecurityCopy;
    twoFactor: {
        enabled: boolean;
        pending: boolean;
        required: boolean;
        remainingCodes: number;
    };
    setup: { qr: string; key: string } | null;
    sessions: SessionItem[];
}

function RecoveryCodes({ copy, codes }: { copy: SecurityCopy['recovery']; codes: string[] }) {
    const [saved, setSaved] = useState(false);
    const [copied, setCopied] = useState(false);

    const copyAll = () => {
        void navigator.clipboard.writeText(codes.join('\n')).then(() => {
            setCopied(true);
        });
    };

    const download = () => {
        const url = URL.createObjectURL(new Blob([`${codes.join('\n')}\n`], { type: 'text/plain' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = copy.file_name;
        link.click();
        URL.revokeObjectURL(url);
    };

    return (
        <section aria-labelledby="codigos" className="flex flex-col gap-4">
            <h2 id="codigos" className="font-display text-xl font-semibold">
                {copy.title}
            </h2>
            <Alert variant="warning">{copy.warning}</Alert>
            <ul className="grid grid-cols-2 gap-2 rounded-md bg-surface-sunken p-4 font-mono text-sm text-text">
                {codes.map((code) => (
                    <li key={code}>{code}</li>
                ))}
            </ul>
            <div className="flex flex-wrap gap-3">
                <Button variant="outline" onClick={copyAll}>
                    {copy.copy_all}
                </Button>
                <Button variant="outline" onClick={download}>
                    {copy.download}
                </Button>
            </div>
            <p role="status" className="text-sm text-text-secondary">
                {copied ? copy.copied : ''}
            </p>
            <Checkbox
                label={copy.saved}
                checked={saved}
                onChange={(event) => {
                    setSaved(event.target.checked);
                }}
            />
            <Button
                size="lg"
                fullWidth
                disabled={!saved}
                onClick={() => {
                    router.visit('/settings/security');
                }}
            >
                {copy.continue}
            </Button>
        </section>
    );
}

export default function Security({ copy, twoFactor, setup, sessions }: SecurityProps) {
    const { props } = usePage<SharedProps>();
    const enable = useForm({});
    const confirm = useForm({ code: '' });
    const disable = useForm({});
    const regenerate = useForm({});
    const [attempt, setAttempt] = useState(0);

    const issuedCodes = props.flash.recovery_codes;
    const actionError = props.errors.two_factor;

    const remainingText = (): string | null => {
        if (!twoFactor.enabled) return null;
        const low = twoFactor.remainingCodes < 3;
        if (twoFactor.remainingCodes === 0) return copy.two_factor.codes_none;
        if (twoFactor.remainingCodes === 1) return low ? copy.two_factor.codes_low_one : copy.two_factor.codes_left_one;

        return (low ? copy.two_factor.codes_low : copy.two_factor.codes_left).replace(':count', String(twoFactor.remainingCodes));
    };

    if (issuedCodes !== null) {
        return (
            <PanelLayout title={copy.title} section="security">
                <RecoveryCodes copy={copy.recovery} codes={issuedCodes} />
            </PanelLayout>
        );
    }

    const remaining = remainingText();

    return (
        <PanelLayout title={copy.title} section="security">
            {props.flash.status !== null && <Alert variant="success">{props.flash.status}</Alert>}

            <section aria-labelledby="dos-pasos" className="flex flex-col gap-4">
                <div className="flex flex-col gap-2">
                    <h2 id="dos-pasos" className="font-display text-xl font-semibold">
                        {copy.two_factor.title}
                    </h2>
                    <p className="text-text-secondary">{copy.two_factor.detail}</p>
                    <p className="text-sm">
                        <span className="font-medium">{twoFactor.enabled ? copy.two_factor.status_on : copy.two_factor.status_off}</span>
                    </p>
                </div>

                {twoFactor.required && !twoFactor.enabled && <Alert variant="warning">{copy.two_factor.required_notice}</Alert>}
                {actionError !== undefined && <Alert variant="danger">{actionError}</Alert>}

                {!twoFactor.enabled && !twoFactor.pending && (
                    <Button
                        size="lg"
                        fullWidth
                        loading={enable.processing}
                        loadingLabel={copy.two_factor.enabling}
                        onClick={() => {
                            enable.post('/user/two-factor-authentication');
                        }}
                    >
                        {copy.two_factor.enable}
                    </Button>
                )}

                {twoFactor.pending && setup !== null && (
                    <div className="flex flex-col gap-4">
                        <h3 className="font-display text-lg font-medium">{copy.two_factor.setup_title}</h3>
                        <p className="text-text-secondary">{copy.two_factor.setup_detail}</p>
                        {/* El QR es una imagen SVG en data: URI: nunca se inserta como HTML. */}
                        {/* Margen claro alrededor del QR: los lectores lo necesitan para reconocerlo. */}
                        <div className="self-center rounded-md bg-[var(--color-text-on-dark)] p-3">
                            <img src={setup.qr} alt={copy.two_factor.qr_alt} className="size-48" />
                        </div>
                        <p className="text-sm text-text-secondary">{copy.two_factor.manual_key}</p>
                        <p className="rounded-md bg-surface-sunken p-3 text-center font-mono text-md tracking-wide">{setup.key}</p>

                        <form
                            noValidate
                            className="flex flex-col gap-4"
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (confirm.data.code.length === 6) {
                                    confirm.post('/user/confirmed-two-factor-authentication', {
                                        errorBag: 'confirmTwoFactorAuthentication',
                                        onError: () => {
                                            confirm.setData('code', '');
                                            setAttempt((current) => current + 1);
                                        },
                                    });
                                }
                            }}
                        >
                            <OtpInput
                                key={attempt}
                                label={copy.two_factor.code_label}
                                digitLabel={(position, total) => `${String(position)}/${String(total)}`}
                                error={confirm.errors.code === undefined ? undefined : copy.two_factor.invalid_code}
                                disabled={confirm.processing}
                                onChange={(code) => {
                                    confirm.setData('code', code);
                                }}
                            />
                            <Button
                                type="submit"
                                size="lg"
                                fullWidth
                                loading={confirm.processing}
                                loadingLabel={copy.two_factor.confirming}
                            >
                                {copy.two_factor.confirm}
                            </Button>
                        </form>

                        <Button
                            variant="text"
                            loading={disable.processing}
                            loadingLabel={copy.two_factor.disabling}
                            onClick={() => {
                                disable.delete('/user/two-factor-authentication');
                            }}
                        >
                            {copy.two_factor.cancel}
                        </Button>
                    </div>
                )}

                {twoFactor.enabled && (
                    <div className="flex flex-col gap-4">
                        <p className="text-text-secondary">{copy.two_factor.enabled_detail}</p>
                        {remaining !== null && (
                            <Alert variant={twoFactor.remainingCodes < 3 ? 'warning' : 'info'}>{remaining}</Alert>
                        )}

                        <p className="text-sm text-text-secondary">{copy.recovery.regenerate_detail}</p>
                        <Button
                            variant="outline"
                            size="lg"
                            fullWidth
                            loading={regenerate.processing}
                            loadingLabel={copy.recovery.regenerating}
                            onClick={() => {
                                regenerate.post('/user/two-factor-recovery-codes');
                            }}
                        >
                            {copy.recovery.regenerate}
                        </Button>

                        {!twoFactor.required && (
                            <Button
                                variant="text"
                                loading={disable.processing}
                                loadingLabel={copy.two_factor.disabling}
                                onClick={() => {
                                    disable.delete('/user/two-factor-authentication');
                                }}
                            >
                                {copy.two_factor.disable}
                            </Button>
                        )}
                    </div>
                )}
            </section>

            <PasswordChangeForm copy={copy.password} />

            <SessionsList copy={copy.sessions} sessions={sessions} />

            {!twoFactor.required || twoFactor.enabled ? (
                <Link href="/account" className="self-start text-sm text-text-secondary underline hover:text-text">
                    {copy.back}
                </Link>
            ) : null}
        </PanelLayout>
    );
}
