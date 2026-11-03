<?php

declare(strict_types=1);

namespace App\Models;

use App\Knowledge\ArticleStatus;
use App\Knowledge\IndexStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un artículo de la base de conocimiento de la empresa. Todo lo fija `App\Knowledge\Articles`: nada es rellenable.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $title
 * @property string $body
 * @property string|null $category
 * @property ArticleStatus $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $published_at
 * @property IndexStatus|null $index_status
 * @property Carbon|null $indexed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class KbArticle extends Model
{
    use BelongsToTenant;

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPublished(): bool
    {
        return $this->status === ArticleStatus::Published;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => ArticleStatus::class, 'published_at' => 'datetime', 'index_status' => IndexStatus::class, 'indexed_at' => 'datetime'];
    }
}
