<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface as Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un fragmento de un artículo o de un documento publicados. Solo lo escribe `App\Knowledge\Chunks`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $article_id
 * @property int|null $document_id
 * @property int $position
 * @property string $body
 * @property Carbon|null $created_at
 */
class KbChunk extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<KbArticle, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(KbArticle::class, 'article_id');
    }

    /**
     * @return BelongsTo<KbDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(KbDocument::class, 'document_id');
    }
}
