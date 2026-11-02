<?php

declare(strict_types=1);

namespace App\Models;

use App\Branding\Palette;
use App\Models\Concerns\BelongsToTenant;
use App\Widget\WidgetCorner;
use App\Widget\WidgetMode;
use Illuminate\Database\Eloquent\Model;

/**
 * La marca de una empresa. Sin fila, se usa la marca por defecto.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $logo_file_id
 * @property array<string, string>|null $colors
 * @property array<string, string>|null $foregrounds
 * @property WidgetMode $widget_mode
 * @property WidgetCorner $widget_corner
 */
class TenantBranding extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_branding';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['colors' => 'array', 'foregrounds' => 'array', 'widget_mode' => WidgetMode::class, 'widget_corner' => WidgetCorner::class];
    }

    /**
     * La marca de la empresa en contexto, creándola vacía la primera vez que se necesita.
     */
    public static function current(): self
    {
        return self::query()->first() ?? self::query()->create([]);
    }

    /**
     * Variables CSS que el panel aplica (primario y su texto); vacío si la empresa usa la paleta por defecto.
     *
     * @return array<string, string>
     */
    public function panelVariables(): array
    {
        return Palette::panelVariables($this->colors, $this->foregrounds);
    }

    /**
     * Variables CSS que el widget aplica: cada color que la empresa cambió con su texto ya calculado. Lo demás lo ponen
     * los tokens del sistema.
     *
     * @return array<string, string>
     */
    public function widgetVariables(): array
    {
        return Palette::widgetVariables($this->colors, $this->foregrounds);
    }

    /**
     * El logo actual, si lo hay. Se busca con el filtro de empresa, así que nunca devuelve el de otra.
     */
    public function logo(): ?StoredFile
    {
        return $this->logo_file_id === null ? null : StoredFile::query()->find($this->logo_file_id);
    }
}
