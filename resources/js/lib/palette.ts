export interface PaletteIssue {
    key: string;
    kind: string;
    message: string;
    suggestion: { hex: string; ratio: string } | null;
}

export interface PaletteReport {
    ok: boolean;
    colors: Record<string, string>;
    foregrounds: Record<string, string>;
    issues: PaletteIssue[];
}

export const colorKeys = ['primary', 'secondary', 'accent', 'background', 'surface', 'text', 'success', 'warning', 'danger', 'info'] as const;
export type ColorKey = (typeof colorKeys)[number];
export type Palette = Record<ColorKey, string>;

const hexPattern = /^#[0-9A-Fa-f]{6}$/;

export const isHex = (value: string): boolean => hexPattern.test(value);

/** Solo los colores que difieren de los de fábrica: es lo que se consulta y se guarda. */
export function overrides(colors: Palette, defaults: Palette): Partial<Palette> {
    const changed: Partial<Palette> = {};

    for (const key of colorKeys) {
        if (colors[key].toUpperCase() !== defaults[key].toUpperCase()) changed[key] = colors[key].toUpperCase();
    }

    return changed;
}

function xsrfToken(): string {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    return cookie === undefined ? '' : decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
}

/**
 * Pregunta al servidor si la paleta cumple el contraste. El cálculo es solo del servidor: aquí no se repite, para que
 * el panel, la vista previa y los correos nunca puedan mostrar resultados distintos.
 */
export async function checkPalette(colors: Partial<Palette>, signal?: AbortSignal): Promise<PaletteReport | null> {
    try {
        const response = await fetch('/settings/branding/palette/check', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify({ colors }),
            ...(signal === undefined ? {} : { signal }),
        });

        return response.ok ? ((await response.json()) as PaletteReport) : null;
    } catch {
        return null;
    }
}
