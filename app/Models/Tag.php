<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Tickets\TagTone;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Etiqueta de clasificación de una empresa. El nombre es texto libre; el tono sale de una lista cerrada y lo fija la
 * acción que la crea.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property TagTone $tone
 */
#[Fillable(['name'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use BelongsToTenant, HasFactory;

    /** Caracteres que admite el nombre. */
    public const MAX_NAME_LENGTH = 30;

    /** Etiquetas que puede tener una empresa y que puede llevar un ticket. */
    public const MAX_PER_TENANT = 100;

    public const MAX_PER_TICKET = 10;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tone' => TagTone::class];
    }
}
