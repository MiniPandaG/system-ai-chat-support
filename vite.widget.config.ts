import { defineConfig } from 'vite';

// El script que las empresas pegan en su sitio (resources/js/widget/loader.ts). Necesita una dirección fija y no puede
// llevar el hash de la compilación, así que se construye aparte del resto, como un archivo único en public/widget.js.
export default defineConfig({
    publicDir: false,
    build: {
        outDir: 'public',
        emptyOutDir: false,
        target: 'es2019',
        lib: {
            entry: 'resources/js/widget/loader.ts',
            formats: ['iife'],
            name: 'GeckoChatLoader',
            fileName: () => 'widget.js',
        },
    },
});
