import { createInertiaApp } from '@inertiajs/react';
import type { ResolvedComponent } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

interface PageModule {
    default: ResolvedComponent;
}

// Inertia inyecta un <style> para la barra de progreso; la CSP solo lo admite con el nonce de la página.
// La propiedad .nonce sigue disponible aunque el navegador oculte el atributo.
const nonce = document.querySelector<HTMLScriptElement>('script[nonce]')?.nonce;

void createInertiaApp({
    ...(nonce ? { nonce } : {}),
    resolve: async (name) => {
        const page = await resolvePageComponent<PageModule>(
            `./pages/${name}.tsx`,
            import.meta.glob<PageModule>('./pages/**/*.tsx'),
        );

        return page.default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
});
