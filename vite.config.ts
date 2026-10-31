import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { defineConfig } from 'vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    build: {
        // La CSP no admite data: en font-src, así que ninguna tipografía se incrusta en el CSS.
        assetsInlineLimit: 0,
    },
    server: {
        // Escucha en todas las interfaces del contenedor; Docker solo lo publica en 127.0.0.1.
        host: true,
        // Sin origin, la URL del archivo public/hot sería http://[::]:5173 y la CSP la bloquearía.
        origin: 'http://127.0.0.1:5173',
        // La página viene de nginx (puerto 8080) y pide los módulos a Vite (5173): solo se admite ese origen.
        cors: { origin: ['http://127.0.0.1:8080', 'http://localhost:8080'] },
        port: 5173,
        strictPort: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
