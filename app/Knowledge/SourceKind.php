<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Models\KbArticle;
use App\Models\KbDocument;
use Illuminate\Database\Eloquent\Model;

/** De dónde sale un fragmento: de un artículo o de un documento. */
enum SourceKind: string
{
    case Article = 'article';
    case Document = 'document';

    /** La columna de `kb_chunks` que apunta a la fuente. */
    public function column(): string
    {
        return $this === self::Article ? 'article_id' : 'document_id';
    }

    /**
     * @return class-string<KbArticle|KbDocument>
     */
    public function model(): string
    {
        return $this === self::Article ? KbArticle::class : KbDocument::class;
    }

    public static function of(Model $source): self
    {
        return $source instanceof KbArticle ? self::Article : self::Document;
    }
}
