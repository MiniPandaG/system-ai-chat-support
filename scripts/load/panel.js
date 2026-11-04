// Carga del panel (RNF-08): un agente inicia sesión y recorre la bandeja y un ticket, como en un día normal.
import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Trend } from 'k6/metrics';

const ok = new Counter('respuestas_200');
const limited = new Counter('respuestas_429');
const failed = new Counter('respuestas_otras');
const inboxTime = new Trend('inbox_ms', true);
const ticketTime = new Trend('ticket_ms', true);

export const options = {
    // Las cookies sobreviven entre vueltas: el navegador inicia sesión una vez y sigue con su sesión.
    noCookiesReset: true,
    scenarios: {
        agentes: { executor: 'ramping-vus', startVUs: 1, stages: [{ duration: '20s', target: 10 }, { duration: '40s', target: 10 }, { duration: '10s', target: 0 }] },
    },
    thresholds: {
        inbox_ms: ['p(95)<2000'],
        ticket_ms: ['p(95)<2000'],
        http_req_failed: ['rate<0.01'],
    },
};

const base = __ENV.BASE_URL;
const agents = JSON.parse(__ENV.AGENTS);

function count(response) {
    if (response.status === 200) ok.add(1);
    else if (response.status === 429) limited.add(1);
    else failed.add(1);
}

function login() {
    const agent = agents[(__VU - 1) % agents.length];
    http.get(`${base}/login`);
    const xsrf = decodeURIComponent(http.cookieJar().cookiesForURL(`${base}/`)['XSRF-TOKEN'][0]);
    const response = http.post(`${base}/login`, JSON.stringify({ email: agent.email, password: agent.password }), {
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf },
    });
    check(response, { 'inicio de sesión': (r) => r.status === 200 || r.status === 204 });
}

// Cada navegador inicia sesión una sola vez, como una persona real (el inicio de sesión tiene un tope de 30 por minuto por IP y todos
// los navegadores de la prueba salen de la misma).
let signedIn = false;

export default function () {
    if (!signedIn) {
        login();
        signedIn = true;
    }

    for (let i = 0; i < 20; i++) {
        const inbox = http.get(`${base}/tickets`, { tags: { name: 'bandeja' } });
        inboxTime.add(inbox.timings.duration);
        count(inbox);
        check(inbox, { 'bandeja 200': (r) => r.status === 200 });

        const filtered = http.get(`${base}/tickets?status[]=open&priority[]=urgent`, { tags: { name: 'bandeja filtrada' } });
        inboxTime.add(filtered.timings.duration);
        count(filtered);
        check(filtered, { 'filtrada 200': (r) => r.status === 200 });

        const ticket = http.get(`${base}/tickets/${__ENV.FIRST_TICKET}`, { tags: { name: 'ticket' } });
        ticketTime.add(ticket.timings.duration);
        count(ticket);
        check(ticket, { 'ticket 200': (r) => r.status === 200 });

        sleep(1);
    }
}
