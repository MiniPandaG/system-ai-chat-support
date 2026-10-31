<?php

declare(strict_types=1);

namespace App\Branding;

/**
 * Lista cerrada de los colores que una empresa puede configurar (D-15). Ninguna otra clave se acepta jamás.
 */
enum BrandColor: string
{
    case Primary = 'primary';
    case Secondary = 'secondary';
    case Accent = 'accent';
    case Background = 'background';
    case Surface = 'surface';
    case Text = 'text';
    case Success = 'success';
    case Warning = 'warning';
    case Danger = 'danger';
    case Info = 'info';

    public function defaultHex(): string
    {
        return config()->string('branding.defaults.'.$this->value);
    }

    /** Los que se usan como fondo de algo con texto encima y por eso llevan un color de texto calculado. */
    public function hasForeground(): bool
    {
        return in_array($this, [self::Primary, self::Secondary, self::Accent, self::Success, self::Warning, self::Danger, self::Info], true);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(fn (self $color): string => $color->value, self::cases());
    }
}
