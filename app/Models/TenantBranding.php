<?php

declare(strict_types=1);

namespace App\Models;

use App\Branding\Palette;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * La marca de una empresa. Sin fila, se usa la marca por defecto.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string|null $logo_file_id
 * @property array<string, string>|null $colors
 * @property array<string, string>|null $foregrounds
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
        return ['colors' => 'array', 'foregrounds' => 'array'];
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
     * El logo actual, si lo hay. Se busca con el filtro de empresa, así que nunca devuelve el de otra.
     */
    public function logo(): ?StoredFile
    {
        return $this->logo_file_id === null ? null : StoredFile::query()->find($this->logo_file_id);
    }
}
