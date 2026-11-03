import { Link } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Alert } from '../../components/Alert';
import { Badge } from '../../components/Badge';
import { Button } from '../../components/Button';
import { TextField } from '../../components/TextField';
import { PanelLayout } from '../../layouts/PanelLayout';
import { bodySchema, endpointsByTag, exampleOf, parametersOf, resolve, typeLabel } from '../../lib/openapi';
import type { Endpoint, Parameter, Spec } from '../../lib/openapi';

interface ApiDocsCopy {
    title: string;
    detail: string;
    keys: string;
    base: string;
    try_title: string;
    try_help: string;
    try_key: string;
    try_key_help: string;
    permission: string;
    permission_read: string;
    permission_write: string;
    permission_admin: string;
    parameters: string;
    body: string;
    responses: string;
    required: string;
    optional: string;
    path_label: string;
    query_label: string;
    send: string;
    sending: string;
    status: string;
    network_error: string;
    invalid_json: string;
    idempotency_hint: string;
    show: string;
    hide: string;
}

interface ApiDocsProps {
    copy: ApiDocsCopy;
    spec: Spec;
    base: string;
}

/** Texto con `código` y **negrita** como elementos de React: nunca se interpreta HTML. */
function inline(text: string): ReactNode[] {
    return text.split(/(`[^`]+`|\*\*[^*]+\*\*)/).map((part, index) => {
        if (part.startsWith('`') && part.endsWith('`') && part.length > 1) {
            return (
                <code key={`c-${part}-${String(index)}`} className="rounded-sm bg-surface-sunken px-1 font-mono text-sm">
                    {part.slice(1, -1)}
                </code>
            );
        }
        if (part.startsWith('**') && part.endsWith('**') && part.length > 3) return <strong key={`b-${part}-${String(index)}`}>{part.slice(2, -2)}</strong>;

        return part;
    });
}

const methodVariant = { get: 'info', post: 'success', patch: 'warning' } as const;

/**
 * Documentación interactiva de la API (RF-26): se dibuja a partir de la especificación OpenAPI y deja probar cada ruta
 * desde el navegador con una llave. La llave que se escribe vive solo en esta pantalla (memoria): no se guarda ni se envía a
 * ningún sitio que no sea la propia API de esta instalación.
 */
export default function ApiDocs({ copy, spec, base }: ApiDocsProps) {
    const [key, setKey] = useState('');
    const groups = endpointsByTag(spec);

    return (
        <PanelLayout title={copy.title} section="api_keys" wide>
            <div className="flex flex-col gap-4">
                <p className="text-text-secondary">{copy.detail}</p>
                <Link href="/settings/api-keys" className="w-fit text-base text-text underline underline-offset-2 hover:text-primary">
                    {copy.keys}
                </Link>
                <section className="flex flex-col gap-3 rounded-md border border-border bg-surface p-4" aria-labelledby="docs-intro">
                    <h2 id="docs-intro" className="font-display text-lg font-semibold">
                        {spec.info.title} <span className="font-body text-sm font-normal text-text-secondary">v{spec.info.version}</span>
                    </h2>
                    {spec.info.description.split('\n\n').map((paragraph) => (
                        <p key={paragraph} className="text-base text-text-secondary">
                            {inline(paragraph)}
                        </p>
                    ))}
                    <p className="text-sm text-text-secondary">{copy.base}</p>
                    <pre className="overflow-x-auto rounded-sm bg-surface-sunken p-3 font-mono text-sm">
                        <code>{base}</code>
                    </pre>
                </section>

                <section className="flex flex-col gap-3 rounded-md border border-border bg-surface p-4" aria-labelledby="docs-try">
                    <h2 id="docs-try" className="font-display text-lg font-semibold">
                        {copy.try_title}
                    </h2>
                    <p className="text-sm text-text-secondary">{copy.try_help}</p>
                    <TextField
                        label={copy.try_key}
                        help={copy.try_key_help}
                        type="password"
                        autoComplete="off"
                        spellCheck={false}
                        value={key}
                        onChange={(event) => {
                            setKey(event.target.value);
                        }}
                        showLabel={copy.show}
                        hideLabel={copy.hide}
                    />
                </section>
            </div>

            {groups.map((group) => (
                <section key={group.tag} className="flex flex-col gap-3" aria-labelledby={`tag-${group.tag}`}>
                    <h2 id={`tag-${group.tag}`} className="font-display text-xl font-semibold">
                        {group.tag}
                    </h2>
                    {group.endpoints.map((endpoint) => (
                        <EndpointCard key={`${endpoint.method}${endpoint.path}`} endpoint={endpoint} spec={spec} base={base} copy={copy} apiKey={key} />
                    ))}
                </section>
            ))}
        </PanelLayout>
    );
}

interface EndpointCardProps {
    endpoint: Endpoint;
    spec: Spec;
    base: string;
    copy: ApiDocsCopy;
    apiKey: string;
}

function EndpointCard({ endpoint, spec, base, copy, apiKey }: EndpointCardProps) {
    const { method, path, operation } = endpoint;
    const [open, setOpen] = useState(false);
    const parameters = parametersOf(spec, operation);
    const schema = bodySchema(spec, operation);
    const permission = { read: copy.permission_read, write: copy.permission_write, admin: copy.permission_admin }[operation['x-permission']];
    const id = `${method}-${path.replace(/\W+/g, '-')}`;

    return (
        <article className="rounded-md border border-border bg-surface" aria-labelledby={`${id}-title`}>
            <h3 id={`${id}-title`} className="m-0">
                <button
                    type="button"
                    aria-expanded={open}
                    aria-controls={`${id}-body`}
                    onClick={() => {
                        setOpen(!open);
                    }}
                    className="flex w-full flex-wrap items-center gap-3 p-4 text-left hover:bg-surface-hover"
                >
                    <Badge variant={methodVariant[method]}>{method.toUpperCase()}</Badge>
                    <span className="break-all font-mono text-base">{path}</span>
                    <span className="text-base text-text-secondary">{operation.summary}</span>
                    <span className="ml-auto">
                        <Badge variant="neutral">{`${copy.permission}: ${permission}`}</Badge>
                    </span>
                </button>
            </h3>
            {open && (
                <div id={`${id}-body`} className="flex flex-col gap-4 border-t border-border p-4">
                    <p className="text-base text-text-secondary">{inline(operation.description)}</p>

                    {parameters.length > 0 && (
                        <div className="flex flex-col gap-2">
                            <h4 className="text-sm font-medium">{copy.parameters}</h4>
                            <ul className="flex flex-col gap-2">
                                {parameters.map((parameter) => (
                                    <li key={`${parameter.in}-${parameter.name}`} className="text-sm text-text-secondary">
                                        <code className="font-mono text-text">{parameter.name}</code> <span className="text-text-tertiary">({parameter.in}, {typeLabel(parameter.schema ?? {})}, {parameter.required === true ? copy.required : copy.optional})</span> {inline(parameter.description ?? '')}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {schema?.properties !== undefined && (
                        <div className="flex flex-col gap-2">
                            <h4 className="text-sm font-medium">{copy.body}</h4>
                            {schema.description !== undefined && <p className="text-sm text-text-secondary">{inline(schema.description)}</p>}
                            <ul className="flex flex-col gap-2">
                                {Object.entries(schema.properties).map(([name, field]) => (
                                    <li key={name} className="text-sm text-text-secondary">
                                        <code className="font-mono text-text">{name}</code> <span className="text-text-tertiary">({typeLabel(field)}, {schema.required?.includes(name) === true ? copy.required : copy.optional})</span> {inline(field.description ?? '')}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    <div className="flex flex-col gap-2">
                        <h4 className="text-sm font-medium">{copy.responses}</h4>
                        <ul className="flex flex-wrap gap-2">
                            {Object.entries(operation.responses).map(([status, response]) => (
                                <li key={status}>
                                    <Badge variant={status.startsWith('2') ? 'success' : 'neutral'}>{`${status} ${resolve(spec, response).description ?? ''}`.trim()}</Badge>
                                </li>
                            ))}
                        </ul>
                    </div>

                    <TryIt endpoint={endpoint} parameters={parameters} schema={schema} base={base} copy={copy} apiKey={apiKey} />
                </div>
            )}
        </article>
    );
}

interface TryItProps {
    endpoint: Endpoint;
    parameters: Parameter[];
    schema: ReturnType<typeof bodySchema>;
    base: string;
    copy: ApiDocsCopy;
    apiKey: string;
}

interface Outcome {
    status: number;
    body: string;
}

/** Prueba la ruta desde el navegador, con la llave de la pantalla. Solo se llama a la API de esta misma instalación. */
function TryIt({ endpoint, parameters, schema, base, copy, apiKey }: TryItProps) {
    const pathParameters = parameters.filter((parameter) => parameter.in === 'path');
    const queryParameters = parameters.filter((parameter) => parameter.in === 'query');
    const [values, setValues] = useState<Record<string, string>>({});
    const [body, setBody] = useState(() => exampleOf(schema));
    const [busy, setBusy] = useState(false);
    const [outcome, setOutcome] = useState<Outcome | null>(null);
    const [problem, setProblem] = useState<string | null>(null);
    const writes = endpoint.method !== 'get';

    const send = async () => {
        setProblem(null);
        setOutcome(null);

        let url = endpoint.path;
        for (const parameter of pathParameters) url = url.replace(`{${parameter.name}}`, encodeURIComponent(values[parameter.name] ?? ''));

        const query = new URLSearchParams();
        for (const parameter of queryParameters) {
            const value = values[parameter.name];
            if (value !== undefined && value !== '') query.set(parameter.name, value);
        }

        let payload: string | undefined;
        if (schema !== null && writes) {
            try {
                payload = JSON.stringify(JSON.parse(body));
            } catch {
                setProblem(copy.invalid_json);

                return;
            }
        }

        setBusy(true);
        try {
            const response = await fetch(`${base}${url}${query.size > 0 ? `?${query.toString()}` : ''}`, {
                method: endpoint.method.toUpperCase(),
                credentials: 'omit',
                headers: { Accept: 'application/json', Authorization: `Bearer ${apiKey}`, ...(payload === undefined ? {} : { 'Content-Type': 'application/json' }) },
                ...(payload === undefined ? {} : { body: payload }),
            });
            const text = await response.text();
            let pretty = text;
            try {
                pretty = JSON.stringify(JSON.parse(text), null, 2);
            } catch {
                pretty = text;
            }
            setOutcome({ status: response.status, body: pretty });
        } catch {
            setProblem(copy.network_error);
        } finally {
            setBusy(false);
        }
    };

    return (
        <form
            noValidate
            className="flex flex-col gap-3 rounded-md border border-border bg-surface-sunken p-3"
            onSubmit={(event) => {
                event.preventDefault();
                void send();
            }}
        >
            {[...pathParameters, ...queryParameters].map((parameter) => (
                <TextField
                    key={`${parameter.in}-${parameter.name}`}
                    label={`${parameter.in === 'path' ? copy.path_label : copy.query_label}: ${parameter.name}`}
                    autoComplete="off"
                    value={values[parameter.name] ?? ''}
                    onChange={(event) => {
                        setValues({ ...values, [parameter.name]: event.target.value });
                    }}
                />
            ))}
            {schema !== null && writes && (
                <div className="flex flex-col gap-2">
                    <label htmlFor={`body-${endpoint.method}${endpoint.path}`} className="text-sm font-medium">
                        {copy.body}
                    </label>
                    <textarea
                        id={`body-${endpoint.method}${endpoint.path}`}
                        rows={8}
                        spellCheck={false}
                        value={body}
                        onChange={(event) => {
                            setBody(event.target.value);
                        }}
                        className="w-full rounded-sm border border-border bg-surface px-3 py-2 font-mono text-sm text-text outline-none focus:border-primary"
                    />
                </div>
            )}
            <div>
                <Button type="submit" loading={busy} loadingLabel={copy.sending} disabled={apiKey.trim() === ''}>
                    {copy.send}
                </Button>
            </div>
            {problem !== null && <Alert variant="danger">{problem}</Alert>}
            {outcome !== null && (
                <div className="flex flex-col gap-2" aria-live="polite">
                    <p className="text-sm font-medium">
                        {copy.status}: <Badge variant={outcome.status < 300 ? 'success' : 'warning'}>{String(outcome.status)}</Badge>
                    </p>
                    <pre className="max-h-96 overflow-auto rounded-sm bg-surface p-3 font-mono text-sm">
                        <code>{outcome.body}</code>
                    </pre>
                </div>
            )}
        </form>
    );
}
