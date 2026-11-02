// Sirve la página de prueba del widget en otro origen (por defecto http://127.0.0.1:9090), para comprobar el chat
// incrustado en un sitio ajeno. Solo lee index.html, solo escucha en la máquina local y no es parte de la aplicación.
//
//   node scripts/widget-demo/serve.mjs            -> http://127.0.0.1:9090 y http://localhost:9090 (dos orígenes distintos)
//   node scripts/widget-demo/serve.mjs 9191       -> otro puerto
import { readFile } from 'node:fs/promises';
import { createServer } from 'node:http';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const port = Number(process.argv[2] ?? 9090);
const page = join(dirname(fileURLToPath(import.meta.url)), 'index.html');

createServer((request, response) => {
    if (request.method !== 'GET' || new URL(request.url ?? '/', 'http://x').pathname !== '/') {
        response.writeHead(404).end();

        return;
    }

    readFile(page).then(
        (html) => {
            response.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' }).end(html);
        },
        () => {
            response.writeHead(500).end();
        },
    );
}).listen(port, '::', () => {
    console.log(`Página de prueba en http://127.0.0.1:${port}/?key=LLAVE y http://localhost:${port}/?key=LLAVE`);
});
