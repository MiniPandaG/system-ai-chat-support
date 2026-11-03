<?php

declare(strict_types=1);

namespace App\Mail;

use App\Branding\Palette;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Tenancy\TenantContext;
use App\Widget\Origins;

/**
 * Los colores y el logo de la empresa para un correo, ya resueltos. Un correo no lee variables CSS: cada valor sale aquí
 * como un #RRGGBB, con la misma paleta guardada que usan el panel y el widget (el color primario y su texto ya pasaron
 * la validación de contraste al guardarse). El correo es siempre claro, con el fondo y el texto fijos del modo claro.
 */
final class EmailTheme
{
    /**
     * @param  array<string, string>  $colors
     */
    private function __construct(
        public readonly string $company,
        public readonly ?string $logoUrl,
        public readonly array $colors,
    ) {}

    public static function for(Tenant $tenant): self
    {
        $branding = app(TenantContext::class)->run($tenant, fn (): ?TenantBranding => TenantBranding::query()->first());

        $primary = Palette::normalize($branding?->colors['primary'] ?? null) ?? strtoupper(config()->string('branding.defaults.primary'));
        $onPrimary = Palette::normalize($branding?->foregrounds['primary'] ?? null) ?? Palette::foreground($primary)['hex'];

        // El logo solo se ofrece si hay una dirección pública que lo sirva: la del widget, que exige el widget encendido.
        $logo = $branding?->logo_file_id !== null && app(Origins::class)->isEnabled($tenant)
            ? url('/widget/logo?key='.$tenant->widget_key.'&v='.$branding->logo_file_id)
            : null;

        return new self($tenant->name, $logo, [
            'primary' => $primary,
            'on_primary' => $onPrimary,
            'background' => strtoupper(config()->string('branding.email.background')),
            'surface' => strtoupper(config()->string('branding.email.surface')),
            'text' => strtoupper(config()->string('branding.email.text')),
            'muted' => strtoupper(config()->string('branding.email.muted')),
            'border' => strtoupper(config()->string('branding.email.border')),
        ]);
    }

    public function color(string $name): string
    {
        return $this->colors[$name] ?? '#000000';
    }
}
