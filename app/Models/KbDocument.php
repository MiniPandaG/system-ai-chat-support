<?php

declare(strict_types=1);

namespace App\Models;

use App\Knowledge\DocumentFailure;
use App\Knowledge\DocumentStatus;
use App\Knowledge\IndexStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un documento subido a la base de conocimiento. Todo lo fija `App\Knowledge\Documents`: nada es rellenable.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $title
 * @property string $original_name
 * @property string $type
 * @property int $size_bytes
 * @property string $sha256
 * @property int|null $pages
 * @property string|null $category
 * @property string|null $body
 * @property DocumentStatus $status
 * @property DocumentFailure|null $failure
 * @property string $disk
 * @property string|null $path
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $scanned_at
 * @property Carbon|null $published_at
 * @property IndexStatus|null $index_status
 * @property Carbon|null $indexed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class KbDocument extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<KbChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(KbChunk::class, 'document_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'failure' => DocumentFailure::class,
            'scanned_at' => 'datetime',
            'published_at' => 'datetime',
            'index_status' => IndexStatus::class,
            'indexed_at' => 'datetime',
        ];
    }
}
