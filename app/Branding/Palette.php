<?php

declare(strict_types=1);

namespace App\Branding;

/**
 * Reglas de la paleta de una empresa (RF-39, RNF-14): formato estricto, lista cerrada de claves, contraste WCAG 2.1 AA
 * y cálculo del texto sobre cada color. Todo se decide aquí, en el servidor; el navegador solo muestra el resultado.
 * Es el único sitio que convierte colores en variables CSS, y solo emite valores #RRGGBB ya validados.
 */
final class Palette
{
    /**
     * @return string|null El color en mayúsculas, o nulo si no es exactamente #RRGGBB. Se usa \z y no $: un salto de
     *                     línea final también pasaría $ y colaría una línea nueva en el CSS.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^#[0-9A-Fa-f]{6}\z/', $value) !== 1) {
            return null;
        }

        return strtoupper($value);
    }

    /** Luminancia relativa según WCAG 2.1. */
    public static function luminance(string $hex): float
    {
        $channels = array_map(static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, self::channels($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** Razón de contraste WCAG entre dos colores, sin redondear. */
    public static function contrast(string $a, string $b): float
    {
        $first = self::luminance($a);
        $second = self::luminance($b);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    /**
     * El texto, claro u oscuro del sistema, que mejor se lee sobre el color.
     *
     * @return array{hex: string, ratio: float}
     */
    public static function foreground(string $hex): array
    {
        $light = config()->string('branding.foreground_light');
        $dark = config()->string('branding.foreground_dark');
        $onLight = self::contrast($hex, $light);
        $onDark = self::contrast($hex, $dark);

        return $onLight >= $onDark ? ['hex' => $light, 'ratio' => $onLight] : ['hex' => $dark, 'ratio' => $onDark];
    }

    /**
     * Revisa una paleta: claves y formato, y las reglas de contraste. Solo se juzgan los colores que la empresa envía;
     * lo que no envía toma el valor de fábrica.
     *
     * @param  array<array-key, mixed>  $submitted
     */
    public static function check(array $submitted): PaletteReport
    {
        $valid = [];
        $issues = [];

        foreach (BrandColor::cases() as $color) {
            if (! array_key_exists($color->value, $submitted)) {
                continue;
            }

            $hex = self::normalize($submitted[$color->value]);

            if ($hex === null) {
                $issues[] = new PaletteIssue($color->value, 'format', (string) __('branding.palette.format'));

                continue;
            }

            $valid[$color->value] = $hex;
        }

        $colors = [];
        foreach (BrandColor::cases() as $color) {
            $colors[$color->value] = $valid[$color->value] ?? $color->defaultHex();
        }

        $foregrounds = [];
        foreach (BrandColor::cases() as $color) {
            if ($color->hasForeground()) {
                $foregrounds[$color->value] = self::foreground($colors[$color->value])['hex'];
            }
        }

        array_push($issues, ...self::textOnColorIssues($valid, $colors), ...self::textAndBackgroundIssues($valid, $colors), ...self::primaryVisibilityIssues($valid, $colors));

        return new PaletteReport($colors, $foregrounds, self::withoutDuplicates($issues));
    }

    /**
     * Lo que se guarda de una paleta ya revisada: solo los colores que difieren de fábrica y todos los textos
     * calculados (así el widget y los correos leen un único origen).
     *
     * @param  array<array-key, mixed>  $submitted
     * @return array{colors: array<string, string>|null, foregrounds: array<string, string>}
     */
    public static function forStorage(array $submitted): array
    {
        $report = self::check($submitted);
        $overrides = [];

        foreach (BrandColor::cases() as $color) {
            $hex = self::normalize($submitted[$color->value] ?? null);

            if ($hex !== null && $hex !== strtoupper($color->defaultHex())) {
                $overrides[$color->value] = $hex;
            }
        }

        return ['colors' => $overrides === [] ? null : $overrides, 'foregrounds' => $report->foregrounds];
    }

    /**
     * Variables CSS que el panel aplica: solo el primario y su texto (el panel conserva el modo oscuro y el resto de
     * sus colores). Los derivados (hover, superficies, bordes) los calculan los color-mix de tokens.css.
     *
     * @return array<string, string>
     */
    public static function panelVariables(mixed $colors, mixed $foregrounds): array
    {
        $primary = is_array($colors) ? self::normalize($colors['primary'] ?? null) : null;
        $text = is_array($foregrounds) ? self::normalize($foregrounds['primary'] ?? null) : null;

        return $primary === null || $text === null ? [] : ['--color-primary' => $primary, '--color-primary-foreground' => $text];
    }

    /**
     * Bloque CSS a partir de variables. Vuelve a filtrar: solo nombres de la lista cerrada y valores #RRGGBB, de modo
     * que nada que no sea un color puede llegar al CSS, venga de donde venga.
     *
     * @param  array<array-key, mixed>  $variables
     */
    public static function css(array $variables): string
    {
        $allowed = [];
        foreach (BrandColor::keys() as $key) {
            $allowed[] = '--color-'.$key;
            $allowed[] = '--color-'.$key.'-foreground';
        }

        $declarations = '';
        foreach ($variables as $name => $value) {
            $hex = self::normalize($value);

            if (is_string($name) && in_array($name, $allowed, true) && $hex !== null) {
                $declarations .= "{$name}:{$hex};";
            }
        }

        return $declarations === '' ? '' : ':root{'.$declarations.'}';
    }

    /**
     * El texto no se lee sobre un color que lleva texto encima.
     *
     * @param  array<string, string>  $valid
     * @param  array<string, string>  $colors
     * @return list<PaletteIssue>
     */
    private static function textOnColorIssues(array $valid, array $colors): array
    {
        $min = config()->float('branding.min_text_contrast');
        $issues = [];

        foreach ($valid as $key => $hex) {
            $color = BrandColor::from($key);

            if (! $color->hasForeground()) {
                continue;
            }

            $best = self::foreground($hex);

            // Los valores de fábrica los define design/ y no se juzgan aquí: el rojo de error por defecto da 4,4998:1
            // con su texto, 0,0002 bajo el mínimo, y eso se corrige en design/, no rechazando el color de fábrica.
            if ($best['ratio'] >= $min || $hex === strtoupper($color->defaultHex())) {
                continue;
            }

            $suggestion = self::nearestPassing(
                $hex,
                fn (string $candidate): bool => self::foreground($candidate)['ratio'] >= $min && ($key !== 'primary' || self::primaryIsVisible($candidate, $colors)),
                darkerFirst: $best['hex'] === config()->string('branding.foreground_light'),
            );

            $issues[] = self::issue($key, 'foreground', 'branding.palette.foreground', $best['ratio'], $min, $suggestion);
        }

        return $issues;
    }

    /**
     * El texto de la empresa no contrasta con su fondo o su superficie.
     *
     * @param  array<string, string>  $valid
     * @param  array<string, string>  $colors
     * @return list<PaletteIssue>
     */
    private static function textAndBackgroundIssues(array $valid, array $colors): array
    {
        if (! array_key_exists('text', $valid) && ! array_key_exists('background', $valid) && ! array_key_exists('surface', $valid)) {
            return [];
        }

        $min = config()->float('branding.min_text_contrast');
        $worst = min(self::contrast($colors['text'], $colors['background']), self::contrast($colors['text'], $colors['surface']));

        if ($worst >= $min) {
            return [];
        }

        if (array_key_exists('text', $valid)) {
            $suggestion = self::nearestPassing(
                $colors['text'],
                fn (string $candidate): bool => self::contrast($candidate, $colors['background']) >= $min && self::contrast($candidate, $colors['surface']) >= $min,
                darkerFirst: self::luminance($colors['text']) < self::luminance($colors['background']),
            );

            return [self::issue('text', 'text_background', 'branding.palette.text_background', $worst, $min, $suggestion)];
        }

        $key = array_key_exists('background', $valid) ? 'background' : 'surface';
        $suggestion = self::nearestPassing(
            $colors[$key],
            fn (string $candidate): bool => self::contrast($colors['text'], $candidate) >= $min,
            darkerFirst: self::luminance($colors[$key]) < self::luminance($colors['text']),
        );

        return [self::issue($key, 'text_background', 'branding.palette.text_background', $worst, $min, $suggestion)];
    }

    /**
     * El primario casi no se ve sobre el fondo o la superficie: el foco y los bordes de los controles lo usan.
     *
     * @param  array<string, string>  $valid
     * @param  array<string, string>  $colors
     * @return list<PaletteIssue>
     */
    private static function primaryVisibilityIssues(array $valid, array $colors): array
    {
        if (! array_key_exists('primary', $valid) && ! array_key_exists('background', $valid) && ! array_key_exists('surface', $valid)) {
            return [];
        }

        $min = config()->float('branding.min_ui_contrast');
        $worst = self::worstAgainstBackgrounds($colors['primary'], $colors);

        if ($worst >= $min) {
            return [];
        }

        if (array_key_exists('primary', $valid)) {
            $textMin = config()->float('branding.min_text_contrast');
            $suggestion = self::nearestPassing(
                $colors['primary'],
                fn (string $candidate): bool => self::primaryIsVisible($candidate, $colors) && self::foreground($candidate)['ratio'] >= $textMin,
                darkerFirst: self::luminance($colors['primary']) < self::luminance($colors['background']),
            );

            return [self::issue('primary', 'primary_background', 'branding.palette.primary_background', $worst, $min, $suggestion)];
        }

        $key = array_key_exists('background', $valid) ? 'background' : 'surface';
        $suggestion = self::nearestPassing(
            $colors[$key],
            fn (string $candidate): bool => self::contrast($colors['primary'], $candidate) >= $min,
            darkerFirst: self::luminance($colors[$key]) < self::luminance($colors['primary']),
        );

        return [self::issue($key, 'primary_background', 'branding.palette.primary_background', $worst, $min, $suggestion)];
    }

    /**
     * @param  array<string, string>  $colors
     */
    private static function primaryIsVisible(string $primary, array $colors): bool
    {
        return self::worstAgainstBackgrounds($primary, $colors) >= config()->float('branding.min_ui_contrast');
    }

    /**
     * El peor contraste del color contra el fondo y la superficie de la empresa y contra los del panel (que siempre
     * es oscuro).
     *
     * @param  array<string, string>  $colors
     */
    private static function worstAgainstBackgrounds(string $hex, array $colors): float
    {
        return min(
            self::contrast($hex, $colors['background']),
            self::contrast($hex, $colors['surface']),
            self::contrast($hex, config()->string('branding.panel_background')),
            self::contrast($hex, config()->string('branding.panel_surface')),
        );
    }

    /**
     * El tono más cercano que cumple, cambiando solo la luminosidad en pasos pequeños para conservar el tono.
     *
     * @param  callable(string): bool  $passes
     */
    private static function nearestPassing(string $hex, callable $passes, bool $darkerFirst): ?string
    {
        [$hue, $saturation, $lightness] = self::toHsl($hex);
        $step = config()->float('branding.adjust_step') / 100;
        $max = config()->integer('branding.adjust_max_steps');

        for ($i = 1; $i <= $max; $i++) {
            foreach ($darkerFirst ? [-1, 1] : [1, -1] as $direction) {
                $next = $lightness + $direction * $step * $i;

                if ($next < 0 || $next > 1) {
                    continue;
                }

                $candidate = self::fromHsl($hue, $saturation, $next);

                if ($passes($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    private static function issue(string $key, string $kind, string $messageKey, float $ratio, float $min, ?string $suggestion): PaletteIssue
    {
        $replace = ['ratio' => self::format($ratio), 'min' => self::format($min)];

        if ($suggestion === null) {
            return new PaletteIssue($key, $kind, (string) __($messageKey, $replace).' '.__('branding.palette.no_suggestion'));
        }

        $suggestedRatio = self::format(self::suggestionRatio($kind, $key, $suggestion));
        $message = __($messageKey, $replace).' '.__('branding.palette.suggestion', ['hex' => $suggestion, 'suggested' => $suggestedRatio]);

        return new PaletteIssue($key, $kind, (string) $message, $suggestion, $suggestedRatio);
    }

    /** El contraste que logra el tono propuesto en la regla que se incumplía. */
    private static function suggestionRatio(string $kind, string $key, string $suggestion): float
    {
        return match ($kind) {
            'foreground' => self::foreground($suggestion)['ratio'],
            'text_background' => $key === 'text'
                ? min(self::contrast($suggestion, config()->string('branding.defaults.background')), self::contrast($suggestion, config()->string('branding.defaults.surface')))
                : self::contrast($suggestion, config()->string('branding.defaults.text')),
            default => self::contrast($suggestion, config()->string('branding.panel_background')),
        };
    }

    /** Un decimal, hacia abajo: nunca se muestra 4,5 si el valor real es 4,46. */
    private static function format(float $ratio): string
    {
        return number_format(floor($ratio * 10) / 10, 1, ',', '');
    }

    /**
     * @param  list<PaletteIssue>  $issues
     * @return list<PaletteIssue>
     */
    private static function withoutDuplicates(array $issues): array
    {
        $seen = [];
        $unique = [];

        foreach ($issues as $issue) {
            $id = $issue->key.'|'.$issue->kind;

            if (! in_array($id, $seen, true)) {
                $seen[] = $id;
                $unique[] = $issue;
            }
        }

        return $unique;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function channels(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    /**
     * @return array{0: float, 1: float, 2: float} Tono 0-360, saturación y luminosidad 0-1.
     */
    private static function toHsl(string $hex): array
    {
        [$r, $g, $b] = array_map(fn (int $channel): float => $channel / 255, self::channels($hex));
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $lightness = ($max + $min) / 2;
        $delta = $max - $min;

        if ($delta === 0.0) {
            return [0.0, 0.0, $lightness];
        }

        $saturation = $delta / (1 - abs(2 * $lightness - 1));
        $hue = match (true) {
            $max === $r => fmod(($g - $b) / $delta, 6),
            $max === $g => ($b - $r) / $delta + 2,
            default => ($r - $g) / $delta + 4,
        } * 60;

        return [$hue < 0 ? $hue + 360 : $hue, $saturation, $lightness];
    }

    private static function fromHsl(float $hue, float $saturation, float $lightness): string
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $x = $chroma * (1 - abs(fmod($hue / 60, 2) - 1));
        $m = $lightness - $chroma / 2;

        [$r, $g, $b] = match (true) {
            $hue < 60 => [$chroma, $x, 0.0],
            $hue < 120 => [$x, $chroma, 0.0],
            $hue < 180 => [0.0, $chroma, $x],
            $hue < 240 => [0.0, $x, $chroma],
            $hue < 300 => [$x, 0.0, $chroma],
            default => [$chroma, 0.0, $x],
        };

        return sprintf('#%02X%02X%02X', (int) round(($r + $m) * 255), (int) round(($g + $m) * 255), (int) round(($b + $m) * 255));
    }
}
