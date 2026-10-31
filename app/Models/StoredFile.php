<?php

declare(strict_types=1);

namespace App\Models;

use App\Files\FilePurpose;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Un archivo guardado de una empresa. Solo lo crea FileStore, que valida y re-codifica antes de guardar.
 *
 * @property string $id
 * @property int $tenant_id
 * @property FilePurpose $purpose
 * @property string $disk
 * @property string $path
 * @property string $mime
 * @property int $size
 * @property int $width
 * @property int $height
 * @property string $sha256
 * @property int|null $uploaded_by
 */
class StoredFile extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $table = 'files';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['purpose' => FilePurpose::class];
    }
}
