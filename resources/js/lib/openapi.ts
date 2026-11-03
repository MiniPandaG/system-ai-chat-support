// Lo mínimo de OpenAPI 3.1 que necesita la pantalla de documentación. La especificación viene del servidor y es la misma que
// sirve /api/v1/openapi.json; aquí solo se recorre y se resuelven sus referencias.

export interface Schema {
    $ref?: string;
    type?: string | string[];
    description?: string;
    enum?: string[];
    properties?: Record<string, Schema>;
    required?: string[];
    items?: Schema;
    example?: unknown;
    default?: unknown;
    maxLength?: number;
    minLength?: number;
    format?: string;
}

export interface Parameter {
    $ref?: string;
    name: string;
    in: 'query' | 'path' | 'header';
    required?: boolean;
    description?: string;
    schema?: Schema;
}

export interface Operation {
    tags: string[];
    summary: string;
    description: string;
    'x-permission': 'read' | 'write' | 'admin';
    parameters?: Parameter[];
    requestBody?: { required?: boolean; content: Record<string, { schema: Schema }> };
    responses: Record<string, { $ref?: string; description?: string }>;
}

export interface Spec {
    info: { title: string; version: string; description: string };
    tags: { name: string }[];
    paths: Record<string, Record<string, Operation>>;
    components: {
        parameters: Record<string, Parameter>;
        responses: Record<string, { description: string }>;
        schemas: Record<string, Schema>;
    };
}

export interface Endpoint {
    method: 'get' | 'post' | 'patch';
    path: string;
    operation: Operation;
}

export const METHODS = ['get', 'post', 'patch'] as const;

/** Los endpoints agrupados por etiqueta, en el orden de la especificación. */
export function endpointsByTag(spec: Spec): { tag: string; endpoints: Endpoint[] }[] {
    const groups = new Map<string, Endpoint[]>(spec.tags.map((tag) => [tag.name, []]));

    for (const [path, operations] of Object.entries(spec.paths)) {
        for (const method of METHODS) {
            const operation = operations[method];
            if (operation === undefined) continue;

            const tag = operation.tags[0] ?? '';
            groups.set(tag, [...(groups.get(tag) ?? []), { method, path, operation }]);
        }
    }

    return [...groups.entries()].filter(([, endpoints]) => endpoints.length > 0).map(([tag, endpoints]) => ({ tag, endpoints }));
}

/** Sigue una referencia local («#/components/…») dentro de la especificación. */
export function resolve<T>(spec: Spec, value: T & { $ref?: string }): T {
    if (value.$ref === undefined) return value;

    let current: unknown = spec;
    for (const part of value.$ref.replace(/^#\//, '').split('/')) {
        current = typeof current === 'object' && current !== null ? (current as Record<string, unknown>)[part] : undefined;
    }

    return (current ?? value) as T;
}

export function parametersOf(spec: Spec, operation: Operation): Parameter[] {
    return (operation.parameters ?? []).map((parameter) => resolve(spec, parameter));
}

export function bodySchema(spec: Spec, operation: Operation): Schema | null {
    const schema = operation.requestBody?.content['application/json']?.schema;

    return schema === undefined ? null : resolve(spec, schema);
}

/** El tipo de un campo en una línea: «string», «integer o null», «uno de: low, normal». */
export function typeLabel(schema: Schema): string {
    if (schema.enum !== undefined) return schema.enum.join(' | ');
    const type = Array.isArray(schema.type) ? schema.type.join(' o ') : (schema.type ?? 'objeto');

    return type;
}

/** El ejemplo de una petición, como JSON legible. */
export function exampleOf(schema: Schema | null): string {
    return schema?.example === undefined ? '' : JSON.stringify(schema.example, null, 2);
}
