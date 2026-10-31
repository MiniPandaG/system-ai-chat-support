import { Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { SubmitEvent } from 'react';
import { Alert } from '../../components/Alert';
import { Button } from '../../components/Button';
import { PasswordStrength } from '../../components/PasswordStrength';
import { StepIndicator } from '../../components/StepIndicator';
import { TextField } from '../../components/TextField';
import { AuthLayout } from '../../layouts/AuthLayout';
import { evaluatePassword } from '../../lib/password';

interface RegisterCopy {
    title: string;
    steps_label: string;
    step_account: string;
    step_company: string;
    step_status: string;
    account_detail: string;
    company_detail: string;
    name: string;
    email: string;
    password: string;
    confirmation: string;
    company: string;
    show_password: string;
    hide_password: string;
    requirements: string;
    required_suffix: string;
    required: string;
    company_required: string;
    invalid_email: string;
    mismatch: string;
    summary: string;
    continue: string;
    back: string;
    submit: string;
    submitting: string;
    have_account: string;
    login: string;
    strength: {
        label: string;
        empty: string;
        weak: string;
        fair: string;
        strong: string;
        missing: string;
        length: string;
        uppercase: string;
        number: string;
    };
}

type Field = 'name' | 'email' | 'password' | 'password_confirmation' | 'company_name';
type FieldErrors = Partial<Record<Field, string | undefined>>;

const accountFields: Field[] = ['name', 'email', 'password', 'password_confirmation'];
const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

export default function Register({ copy }: { copy: RegisterCopy }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        company_name: '',
    });
    const [step, setStep] = useState(0);
    const [clientErrors, setClientErrors] = useState<FieldErrors>({});
    const [summary, setSummary] = useState('');
    const nameRef = useRef<HTMLInputElement>(null);
    const emailRef = useRef<HTMLInputElement>(null);
    const passwordRef = useRef<HTMLInputElement>(null);
    const confirmationRef = useRef<HTMLInputElement>(null);
    const companyRef = useRef<HTMLInputElement>(null);
    const mountedRef = useRef(false);

    // Al cambiar de paso el foco pasa al primer campo del paso nuevo, no al cuerpo de la página.
    useEffect(() => {
        if (!mountedRef.current) {
            mountedRef.current = true;

            return;
        }

        (step === 0 ? nameRef : companyRef).current?.focus();
    }, [step]);

    const label = (field: Field): string => {
        switch (field) {
            case 'name':
                return copy.name;
            case 'email':
                return copy.email;
            case 'password':
                return copy.password;
            case 'password_confirmation':
                return copy.confirmation;
            case 'company_name':
                return copy.company;
        }
    };

    const focusField = (field: Field) => {
        const targets = { name: nameRef, email: emailRef, password: passwordRef, password_confirmation: confirmationRef, company_name: companyRef };
        targets[field].current?.focus();
    };

    const validate = (field: Field): string | undefined => {
        const value = data[field];

        if (value.trim() === '') {
            if (field === 'company_name') return copy.company_required;
            if (field === 'password_confirmation') return copy.mismatch;

            return copy.required.replace(':field', label(field).toLowerCase());
        }
        if (field === 'email' && !emailPattern.test(value.trim())) return copy.invalid_email;
        if (field === 'password' && evaluatePassword(value).missing.length > 0) return copy.requirements;
        if (field === 'password_confirmation' && value !== data.password) return copy.mismatch;

        return undefined;
    };

    const check = (fields: Field[]): Field[] => {
        const found: FieldErrors = {};
        for (const field of fields) found[field] = validate(field);
        setClientErrors((current) => ({ ...current, ...found }));

        const invalid = fields.filter((field) => found[field] !== undefined);
        if (invalid.length > 0) {
            setSummary(copy.summary.replace(':count', String(invalid.length)).replace(':fields', invalid.map((field) => label(field)).join(', ')));
            focusField(invalid[0] ?? fields[0] ?? 'name');
        } else {
            setSummary('');
        }

        return invalid;
    };

    const submit = (event: SubmitEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (step === 0) {
            if (check(accountFields).length === 0) setStep(1);

            return;
        }

        if (check(['company_name']).length > 0) return;

        post('/register', {
            onError: (serverErrors) => {
                if (accountFields.some((field) => serverErrors[field] !== undefined)) setStep(0);
                if (serverErrors.password !== undefined) reset('password', 'password_confirmation');
            },
        });
    };

    const blur = (field: Field) => () => {
        setClientErrors((current) => ({ ...current, [field]: validate(field) }));
    };

    const stepNames = [copy.step_account, copy.step_company];

    return (
        <AuthLayout title={copy.title}>
            <StepIndicator label={copy.steps_label} steps={stepNames} current={step} />
            <p role="status" className="sr-only">
                {copy.step_status
                    .replace(':current', String(step + 1))
                    .replace(':total', String(stepNames.length))
                    .replace(':name', stepNames[step] ?? '')}
            </p>

            <form onSubmit={submit} noValidate className="flex flex-col gap-6">
                {summary !== '' && <Alert variant="danger">{summary}</Alert>}

                {step === 0 && (
                    <>
                        <p className="text-text-secondary">{copy.account_detail}</p>

                        <TextField
                            label={`${copy.name} ${copy.required_suffix}`}
                            name="name"
                            autoComplete="name"
                            value={data.name}
                            inputRef={nameRef}
                            error={clientErrors.name ?? errors.name}
                            onChange={(event) => {
                                setData('name', event.target.value);
                            }}
                            onBlur={blur('name')}
                        />

                        <TextField
                            label={`${copy.email} ${copy.required_suffix}`}
                            name="email"
                            type="email"
                            autoComplete="email"
                            inputMode="email"
                            value={data.email}
                            inputRef={emailRef}
                            error={clientErrors.email ?? errors.email}
                            onChange={(event) => {
                                setData('email', event.target.value);
                            }}
                            onBlur={blur('email')}
                        />

                        <TextField
                            label={`${copy.password} ${copy.required_suffix}`}
                            name="password"
                            type="password"
                            autoComplete="new-password"
                            value={data.password}
                            inputRef={passwordRef}
                            help={copy.requirements}
                            error={clientErrors.password ?? errors.password}
                            showLabel={copy.show_password}
                            hideLabel={copy.hide_password}
                            onChange={(event) => {
                                setData('password', event.target.value);
                            }}
                            onBlur={blur('password')}
                        />

                        <PasswordStrength password={data.password} copy={copy.strength} />

                        <TextField
                            label={`${copy.confirmation} ${copy.required_suffix}`}
                            name="password_confirmation"
                            type="password"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            inputRef={confirmationRef}
                            error={clientErrors.password_confirmation}
                            showLabel={copy.show_password}
                            hideLabel={copy.hide_password}
                            onChange={(event) => {
                                setData('password_confirmation', event.target.value);
                            }}
                            onBlur={blur('password_confirmation')}
                        />

                        <Button type="submit" size="lg" fullWidth>
                            {copy.continue}
                        </Button>
                    </>
                )}

                {step === 1 && (
                    <>
                        <p className="text-text-secondary">{copy.company_detail}</p>

                        <TextField
                            label={`${copy.company} ${copy.required_suffix}`}
                            name="company_name"
                            autoComplete="organization"
                            value={data.company_name}
                            inputRef={companyRef}
                            error={clientErrors.company_name ?? errors.company_name}
                            onChange={(event) => {
                                setData('company_name', event.target.value);
                            }}
                            onBlur={blur('company_name')}
                        />

                        <div className="flex flex-col gap-3">
                            <Button type="submit" size="lg" fullWidth loading={processing} loadingLabel={copy.submitting}>
                                {copy.submit}
                            </Button>
                            <Button
                                variant="text"
                                disabled={processing}
                                onClick={() => {
                                    setSummary('');
                                    setStep(0);
                                }}
                            >
                                {copy.back}
                            </Button>
                        </div>
                    </>
                )}
            </form>

            <p className="text-sm text-text-secondary">
                {copy.have_account}{' '}
                <Link href="/login" className="underline hover:text-text">
                    {copy.login}
                </Link>
            </p>
        </AuthLayout>
    );
}
