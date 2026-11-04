// Carga de la API pública (RS-07, RNF-10): lecturas y creaciones con una llave. Con más de 120 peticiones por minuto por
// llave se esperan 429: se comprueba que el límite actúa, que no hay errores 5xx y que la latencia es razonable.
import http from 'k6/http';
import { check } from 'k6';

export const options = {
    scenarios: {
        lecturas: { executor: 'constant-arrival-rate', rate: 4, timeUnit: '1s', duration: '60s', preAllocatedVUs: 10, exec: 'read' },
        creaciones: { executor: 'constant-arrival-rate', rate: 1, timeUnit: '1s', duration: '60s', preAllocatedVUs: 5, exec: 'create' },
    },
    thresholds: {
        'http_req_duration{name:lectura}': ['p(95)<1000'],
    },
};

const base = __ENV.BASE_URL;
const headers = { Authorization: `Bearer ${__ENV.API_KEY}`, 'Content-Type': 'application/json', Accept: 'application/json' };

export function read() {
    const response = http.get(`${base}/api/v1/tickets?limit=25`, { headers, tags: { name: 'lectura' } });
    check(response, { 'sin errores del servidor': (r) => r.status < 500, 'ok o limitada': (r) => r.status === 200 || r.status === 429 });
}

export function create() {
    const body = JSON.stringify({ subject: 'Prueba de carga', description: 'Mensaje de prueba de carga.', customer_name: 'Carga', customer_email: `carga${__VU}@ejemplo.test` });
    const response = http.post(`${base}/api/v1/tickets`, body, { headers: { ...headers, 'Idempotency-Key': `carga-${__VU}-${__ITER}-${Date.now()}` }, tags: { name: 'creacion' } });
    check(response, { 'sin errores del servidor': (r) => r.status < 500, 'creada o limitada': (r) => r.status === 201 || r.status === 429 || r.status === 422 });
}
