import { describe, expect, it } from 'vitest';
import { bodySchema, endpointsByTag, exampleOf, parametersOf, resolve, typeLabel } from './openapi';
import type { Spec } from './openapi';

export const miniSpec: Spec = {
    info: { title: 'API de prueba', version: '1.0.0', description: 'Una **API** con `código`.\n\nOtro párrafo.' },
    tags: [{ name: 'Tickets' }, { name: 'Clientes' }, { name: 'Vacía' }],
    paths: {
        '/tickets': {
            get: {
                tags: ['Tickets'],
                summary: 'Listar tickets',
                description: 'Del más reciente al más antiguo.',
                'x-permission': 'read',
                parameters: [{ $ref: '#/components/parameters/Limit' } as never, { name: 'status', in: 'query', description: 'Estado.', schema: { type: 'string', enum: ['open', 'closed'] } }],
                responses: { '200': { description: 'Una página.' }, '401': { $ref: '#/components/responses/Unauthorized' } },
            },
            post: {
                tags: ['Tickets'],
                summary: 'Crear un ticket',
                description: 'Crea uno.',
                'x-permission': 'write',
                requestBody: { required: true, content: { 'application/json': { schema: { $ref: '#/components/schemas/TicketCreate' } } } },
                responses: { '201': { description: 'Creado.' } },
            },
        },
        '/customers/{customer}': {
            get: {
                tags: ['Clientes'],
                summary: 'Consultar un cliente',
                description: 'Uno.',
                'x-permission': 'read',
                parameters: [{ name: 'customer', in: 'path', required: true, description: 'Identificador.', schema: { type: 'integer' } }],
                responses: { '200': { description: 'El cliente.' } },
            },
        },
    },
    components: {
        parameters: { Limit: { name: 'limit', in: 'query', description: 'Cuántos.', schema: { type: 'integer' } } },
        responses: { Unauthorized: { description: 'Falta la llave.' } },
        schemas: {
            TicketCreate: {
                type: 'object',
                required: ['subject'],
                properties: { subject: { type: 'string', description: 'Una línea.' }, priority: { type: 'string', enum: ['low', 'normal'] } },
                example: { subject: 'Hola', priority: 'normal' },
            },
        },
    },
};

describe('openapi', () => {
    it('agrupa los endpoints por etiqueta en el orden de la especificación y omite las etiquetas vacías', () => {
        const groups = endpointsByTag(miniSpec);

        expect(groups.map((group) => group.tag)).toEqual(['Tickets', 'Clientes']);
        expect(groups[0]?.endpoints.map((endpoint) => `${endpoint.method} ${endpoint.path}`)).toEqual(['get /tickets', 'post /tickets']);
    });

    it('resuelve referencias locales y deja intacto lo que no es una referencia', () => {
        expect(resolve<{ $ref?: string; description?: string }>(miniSpec, { $ref: '#/components/responses/Unauthorized' }).description).toBe('Falta la llave.');
        const plain = { description: 'Directa' };

        expect(resolve(miniSpec, plain)).toBe(plain);
        expect(resolve<{ $ref?: string }>(miniSpec, { $ref: '#/components/nada/no-existe' }).$ref).toBe('#/components/nada/no-existe');
    });

    it('lista los parámetros resueltos y el esquema del cuerpo', () => {
        const list = endpointsByTag(miniSpec).flatMap((group) => group.endpoints).find((endpoint) => endpoint.method === 'get' && endpoint.path === '/tickets');
        const create = endpointsByTag(miniSpec).flatMap((group) => group.endpoints).find((endpoint) => endpoint.method === 'post');
        if (list === undefined || create === undefined) {
            throw new Error('La especificación de prueba perdió sus rutas.');
        }

        expect(parametersOf(miniSpec, list.operation).map((parameter) => parameter.name)).toEqual(['limit', 'status']);
        expect(bodySchema(miniSpec, create.operation)?.required).toEqual(['subject']);
        expect(bodySchema(miniSpec, list.operation)).toBeNull();
    });

    it('describe el tipo de un campo en una línea', () => {
        expect(typeLabel({ type: 'string' })).toBe('string');
        expect(typeLabel({ type: ['integer', 'null'] })).toBe('integer o null');
        expect(typeLabel({ type: 'string', enum: ['low', 'normal'] })).toBe('low | normal');
        expect(typeLabel({})).toBe('objeto');
    });

    it('arma el ejemplo de una petición como JSON legible, o nada', () => {
        expect(exampleOf(miniSpec.components.schemas.TicketCreate ?? null)).toBe('{\n  "subject": "Hola",\n  "priority": "normal"\n}');
        expect(exampleOf(null)).toBe('');
        expect(exampleOf({ type: 'object' })).toBe('');
    });
});
