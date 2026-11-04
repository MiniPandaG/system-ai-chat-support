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

export function GaugeIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M4 17a8 8 0 1 1 16 0" />
            <path d="m12 17 4-5" />
            <path d="M12 9V7" />
            <path d="M6.3 11.3 5 10" />
            <path d="m17.7 11.3 1.3-1.3" />
        </Icon>
    );
}

export function InboxIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M3 13h5l1.5 3h5L16 13h5" />
            <path d="M5.5 5h13L21 13v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-6Z" />
        </Icon>
    );
}

export function PlusIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M12 5v14" />
            <path d="M5 12h14" />
        </Icon>
    );
}

export function ArrowLeftIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M19 12H5" />
            <path d="m11 6-6 6 6 6" />
        </Icon>
    );
}

export function PaperclipIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="m21 11.5-8.6 8.6a5 5 0 0 1-7.1-7.1l8.6-8.6a3.5 3.5 0 0 1 5 5l-8.6 8.6a2 2 0 0 1-2.8-2.8l7.9-7.9" />
        </Icon>
    );
}

export function FileIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8Z" />
            <path d="M14 3v5h5" />
        </Icon>
    );
}

export function LockIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <rect x="4" y="11" width="16" height="10" rx="2" />
            <path d="M8 11V7a4 4 0 0 1 8 0v4" />
        </Icon>
    );
}

export function KeyIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <circle cx="8" cy="15" r="4" />
            <path d="M11 12l9-9" />
            <path d="M16 7l3 3" />
        </Icon>
    );
}

export function BookIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v16H6.5A2.5 2.5 0 0 0 4 21.5Z" />
            <path d="M4 18.5A2.5 2.5 0 0 1 6.5 16H20" />
        </Icon>
    );
}

export function SparkIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9Z" />
            <path d="M19 16l.7 1.8L21.5 18.5l-1.8.7L19 21l-.7-1.8-1.8-.7 1.8-.7Z" />
        </Icon>
    );
}

export function ChartIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M4 20V10" />
            <path d="M10 20V4" />
            <path d="M16 20v-8" />
            <path d="M22 20H2" />
        </Icon>
    );
}

export function SmileIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <circle cx="12" cy="12" r="9" />
            <path d="M8.5 14.5c.9 1.2 2 1.8 3.5 1.8s2.6-.6 3.5-1.8" />
            <path d="M9 9.5h.01" />
            <path d="M15 9.5h.01" />
        </Icon>
    );
}

export function MehIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <circle cx="12" cy="12" r="9" />
            <path d="M8.5 15.5h7" />
            <path d="M9 9.5h.01" />
            <path d="M15 9.5h.01" />
        </Icon>
    );
}

export function FrownIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <circle cx="12" cy="12" r="9" />
            <path d="M8.5 16.5c.9-1.2 2-1.8 3.5-1.8s2.6.6 3.5 1.8" />
            <path d="M9 9.5h.01" />
            <path d="M15 9.5h.01" />
        </Icon>
    );
}

export function BuildingIcon(props: IconProps) {
    return (
        <Icon {...props}>
            <path d="M4 21V5a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v16" />
            <path d="M14 10h5a1 1 0 0 1 1 1v10" />
            <path d="M3 21h18" />
            <path d="M8 8h2" />
            <path d="M8 12h2" />
            <path d="M8 16h2" />
        </Icon>
    );
}
