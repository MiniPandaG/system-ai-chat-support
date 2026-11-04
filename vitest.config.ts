import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.{ts,tsx}'],
        setupFiles: ['resources/js/test-setup.ts'],
        // Con 5 s (lo habitual), una máquina cargada o la integración continua hacen fallar al azar pruebas que sí pasan solas.
        testTimeout: 20_000,
    },
});
