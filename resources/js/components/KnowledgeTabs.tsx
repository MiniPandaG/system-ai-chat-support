import { Link } from '@inertiajs/react';

type Section = 'articles' | 'documents' | 'context';

interface KnowledgeTabsProps {
    current: Section;
    label: string;
    articles: string;
    documents: string;
    context: string;
}

/**
 * Las secciones de la base de conocimiento. Son enlaces a direcciones distintas (cada una se puede abrir y compartir), no
 * un `tablist`: la sección actual se marca con `aria-current`.
 */
export function KnowledgeTabs({ current, label, articles, documents, context }: KnowledgeTabsProps) {
    const item = (key: Section, href: string, text: string) => (
        <Link
            href={href}
            aria-current={current === key ? 'page' : undefined}
            className={`h-10 border-b-2 px-4 text-base font-medium leading-10 transition duration-fast ease-standard ${
                current === key ? 'border-primary text-text' : 'border-transparent text-text-secondary hover:text-text'
            }`}
        >
            {text}
        </Link>
    );

    return (
        <nav aria-label={label} className="flex gap-1 overflow-x-auto border-b border-border">
            {item('articles', '/knowledge', articles)}
            {item('documents', '/knowledge/documents', documents)}
            {item('context', '/knowledge/context', context)}
        </nav>
    );
}
