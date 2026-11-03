import { Badge } from './Badge';

export type IndexState = 'pending' | 'indexed' | 'failed';

interface IndexStatusBadgeProps {
    status: IndexState | null;
    pending: string;
    indexed: string;
    failed: string;
}

/**
 * El estado de indexación de lo publicado (design/pantallas/base-conocimiento.md): indexando con un indicador de 12 px,
 * indexado o con error. Lo que no está publicado no se indexa y no lleva insignia. Siempre con texto, nunca solo color.
 */
export function IndexStatusBadge({ status, pending, indexed, failed }: IndexStatusBadgeProps) {
    if (status === null) return null;

    if (status === 'pending') {
        return (
            <Badge variant="info">
                <span aria-hidden="true" className="size-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
                {pending}
            </Badge>
        );
    }

    return <Badge variant={status === 'indexed' ? 'success' : 'danger'}>{status === 'indexed' ? indexed : failed}</Badge>;
}
