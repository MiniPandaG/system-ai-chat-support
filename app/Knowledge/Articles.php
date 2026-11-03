<?php

declare(strict_types=1);

namespace App\Knowledge;

use App\Enums\AuditEvent;
use App\Models\KbArticle;
use App\Models\Tenant;
use App\Models\User;
use App\Plans\PlanLimits;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Crea, edita, publica y borra artículos. Es el único camino de escritura: limpia el texto, fija quién y cuándo, cuida el
 * tope por empresa con la fila de la empresa bloqueada y deja la auditoría solo con identificadores.
 */
class Articles
{
    public function __construct(
        private readonly PlanLimits $limits,
        private readonly AuditLogger $audit,
        private readonly Chunks $chunks,
        private readonly IndexQueue $indexing,
    ) {}

    /**
     * @throws ValidationException
     */
    public function create(User $actor, Tenant $tenant, string $title, string $body, ?string $category): KbArticle
    {
        return $this->limits->exclusively($tenant, function () use ($actor, $title, $body, $category): KbArticle {
            $max = config()->integer('knowledge.articles.max');

            if (KbArticle::query()->count() >= $max) {
                throw ValidationException::withMessages(['title' => __('knowledge.errors.articles_max', ['max' => $max])]);
            }

            $article = new KbArticle;
            $article->forceFill([
                'title' => TextCleaner::line($title),
                'body' => TextCleaner::clean($body),
                'category' => $this->category($category),
                'status' => ArticleStatus::Draft,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->audit->record(AuditEvent::KnowledgeArticleCreated, $actor->id, ['article_id' => $article->id]);

            return $article;
        });
    }

    /**
     * Lo publicado cambia lo que la búsqueda puede citar: sus fragmentos se rehacen con el texto nuevo.
     *
     * @throws ValidationException
     */
    public function update(User $actor, KbArticle $article, string $title, string $body, ?string $category): KbArticle
    {
        DB::transaction(function () use ($actor, $article, $title, $body, $category): void {
            $article->forceFill([
                'title' => TextCleaner::line($title),
                'body' => TextCleaner::clean($body),
                'category' => $this->category($category),
                'updated_by' => $actor->id,
            ])->save();

            if ($article->isPublished()) {
                $this->chunks->replaceForArticle($article);
                $this->indexing->requestIndex($article);
            }
        });

        return $article;
    }

    /**
     * @throws ValidationException
     */
    public function publish(User $actor, KbArticle $article): KbArticle
    {
        if (trim($article->body) === '') {
            throw ValidationException::withMessages(['body' => __('knowledge.errors.publish_empty')]);
        }

        if (! $article->isPublished()) {
            DB::transaction(function () use ($actor, $article): void {
                $article->forceFill(['status' => ArticleStatus::Published, 'published_at' => now(), 'updated_by' => $actor->id])->save();
                $this->chunks->replaceForArticle($article);
                $this->indexing->requestIndex($article);
                $this->audit->record(AuditEvent::KnowledgeArticlePublished, $actor->id, ['article_id' => $article->id]);
            });
        }

        return $article;
    }

    public function unpublish(User $actor, KbArticle $article): KbArticle
    {
        if ($article->isPublished()) {
            DB::transaction(function () use ($actor, $article): void {
                $article->forceFill(['status' => ArticleStatus::Draft, 'published_at' => null, 'updated_by' => $actor->id])->save();
                $this->chunks->purgeForArticle($article);
                $this->indexing->requestPurge($article);
                $this->audit->record(AuditEvent::KnowledgeArticleUnpublished, $actor->id, ['article_id' => $article->id]);
            });
        }

        return $article;
    }

    public function delete(User $actor, KbArticle $article): void
    {
        DB::transaction(function () use ($actor, $article): void {
            $id = $article->id;
            $this->chunks->purgeForArticle($article);
            $this->indexing->requestPurgeById(SourceKind::Article, $id);
            $article->delete();
            $this->audit->record(AuditEvent::KnowledgeArticleDeleted, $actor->id, ['article_id' => $id]);
        });
    }

    private function category(?string $category): ?string
    {
        $clean = $category === null ? '' : TextCleaner::line($category);

        return $clean === '' ? null : $clean;
    }
}
