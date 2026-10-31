import type { SVGProps } from 'react';

type IconProps = Omit<SVGProps<SVGSVGElement>, 'children'>;

function Icon({ children, ...props }: SVGProps<SVGSVGElement>) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth={2}
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
            focusable="false"
            {...props}
        >
            {children}
        </svg>
    );
}

export function EyeIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z" />
            <circle cx="12" cy="12" r="3" />
        </Icon>
    );
}

export function EyeOffIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M3 3l18 18" />
            <path d="M10.6 5.1A9.7 9.7 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3 3.8" />
            <path d="M6.6 6.6A17 17 0 0 0 2 12s3.5 7 10 7a9.6 9.6 0 0 0 4.2-1" />
            <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
        </Icon>
    );
}

export function InfoIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <circle cx="12" cy="12" r="9" />
            <path d="M12 11v5" />
            <path d="M12 8h.01" />
        </Icon>
    );
}

export function CheckCircleIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <circle cx="12" cy="12" r="9" />
            <path d="m8 12.5 2.8 2.8L16 9.8" />
        </Icon>
    );
}

export function AlertTriangleIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M12 3.5 2.5 20h19L12 3.5Z" />
            <path d="M12 10v4.5" />
            <path d="M12 17.5h.01" />
        </Icon>
    );
}
