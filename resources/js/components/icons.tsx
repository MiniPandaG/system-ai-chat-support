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

export function UserIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <circle cx="12" cy="8" r="4" />
            <path d="M4 20c0-4 3.6-6 8-6s8 2 8 6" />
        </Icon>
    );
}

export function ShieldIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M12 3 4.5 6v6c0 4.5 3.2 7.6 7.5 9 4.3-1.4 7.5-4.5 7.5-9V6L12 3Z" />
            <path d="m9 12 2.2 2.2L15.5 10" />
        </Icon>
    );
}

export function MenuIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M4 7h16" />
            <path d="M4 12h16" />
            <path d="M4 17h16" />
        </Icon>
    );
}

export function CheckIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="m5 12.5 4.5 4.5L19 7.5" />
        </Icon>
    );
}

export function UsersIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <circle cx="9" cy="8" r="3.5" />
            <path d="M2.5 20c0-3.6 2.8-5.5 6.5-5.5s6.5 1.9 6.5 5.5" />
            <path d="M16 4.7a3.5 3.5 0 0 1 0 6.6" />
            <path d="M18 14.8c2.2.6 3.5 2.2 3.5 5.2" />
        </Icon>
    );
}

export function XIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M6 6l12 12" />
            <path d="M18 6 6 18" />
        </Icon>
    );
}

export function PaletteIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M12 3.5a8.5 8.5 0 1 0 0 17c1.3 0 2-.8 2-1.7 0-1.3-1.2-1.5-1.2-2.6 0-.9.7-1.5 1.7-1.5H17a3.5 3.5 0 0 0 3.5-3.5C20.5 6.7 16.7 3.5 12 3.5Z" />
            <circle cx="7.8" cy="11" r="1" />
            <circle cx="10.5" cy="7.6" r="1" />
            <circle cx="15" cy="7.8" r="1" />
        </Icon>
    );
}

export function UploadIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M12 16V5" />
            <path d="m7.5 9.5 4.5-4.5 4.5 4.5" />
            <path d="M4.5 16.5V19h15v-2.5" />
        </Icon>
    );
}

export function ChatIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M4 5.5h16v10.5H9.5L5.5 20v-4H4V5.5Z" />
            <path d="M8 10h8" />
            <path d="M8 13h5" />
        </Icon>
    );
}
