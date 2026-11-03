import Markdown from 'react-markdown';
import type { Components, UrlTransform } from 'react-markdown';

/**
 * Solo enlaces http, https y mailto: cualquier otro esquema (javascript:, data:, vbscript:) y los enlaces relativos se
 * quedan sin destino. Es el único filtro de direcciones del contenido que escribe la empresa.
 */
const SAFE_URL = /^(https?:|mailto:)/i;
const urlTransform: UrlTransform = (url) => (SAFE_URL.test(url.trim()) ? url : '');

/** La página ya tiene un h1 (el título del artículo): los títulos del contenido bajan un nivel. */
const components: Components = {
    h1: ({ children }) => <h2 className="mt-6 font-display text-xl font-semibold first:mt-0">{children}</h2>,
    h2: ({ children }) => <h3 className="mt-5 font-display text-lg font-semibold first:mt-0">{children}</h3>,
    h3: ({ children }) => <h4 className="mt-4 font-display text-base font-semibold first:mt-0">{children}</h4>,
    h4: ({ children }) => <h4 className="mt-4 font-display text-base font-semibold first:mt-0">{children}</h4>,
    h5: ({ children }) => <h4 className="mt-4 font-display text-base font-semibold first:mt-0">{children}</h4>,
    h6: ({ children }) => <h4 className="mt-4 font-display text-base font-semibold first:mt-0">{children}</h4>,
    p: ({ children }) => <p className="mt-3 first:mt-0">{children}</p>,
    ul: ({ children }) => <ul className="mt-3 list-disc pl-6 first:mt-0">{children}</ul>,
    ol: ({ children }) => <ol className="mt-3 list-decimal pl-6 first:mt-0">{children}</ol>,
    li: ({ children }) => <li className="mt-1">{children}</li>,
    blockquote: ({ children }) => <blockquote className="mt-3 border-l-2 border-border-strong pl-4 text-text-secondary">{children}</blockquote>,
    hr: () => <hr className="my-6 border-border" />,
    pre: ({ children }) => <pre className="mt-3 overflow-x-auto rounded-sm bg-surface-sunken p-3 font-mono text-sm first:mt-0">{children}</pre>,
    code: ({ children, className }) => <code className={className === undefined ? 'rounded-sm bg-surface-sunken px-1 font-mono text-sm' : 'font-mono'}>{children}</code>,
    a: ({ href, children }) =>
        href === undefined || href === '' ? (
            <span>{children}</span>
        ) : (
            <a href={href} target="_blank" rel="noopener noreferrer nofollow" className="text-primary underline underline-offset-2 hover:text-primary-hover">
                {children}
            </a>
        ),
    // Los artículos no llevan imágenes: se muestra su texto alternativo.
    img: ({ alt }) => <span>{alt}</span>,
};

interface MarkdownViewProps {
    source: string;
    className?: string;
}

/**
 * Muestra contenido en Markdown de la empresa. El HTML escrito dentro del texto no se interpreta (se descarta) y nunca
 * se inserta HTML en la página: lo que sale son elementos de React creados desde el texto.
 */
export function MarkdownView({ source, className = '' }: MarkdownViewProps) {
    return (
        <div className={`break-words text-base text-text ${className}`}>
            <Markdown skipHtml urlTransform={urlTransform} components={components}>
                {source}
            </Markdown>
        </div>
    );
}
