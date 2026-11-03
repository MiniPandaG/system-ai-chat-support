<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SaveArticleRequest;
use App\Knowledge\Articles;
use App\Knowledge\ArticleStatus;
use App\Knowledge\Reindexer;
use App\Models\KbArticle;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * La base de conocimiento de la empresa. Administradores y agentes la leen; solo los administradores la escriben
 * (KbArticlePolicy). Los artículos se resuelven con el filtro automático de empresa: uno de otra empresa da 404.
 */
class KnowledgeController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', KbArticle::class);

        $category = $this->filter($request, 'category');
        $search = $this->filter($request, 'q');
        $status = ArticleStatus::tryFrom($this->filter($request, 'status') ?? '');

        $page = KbArticle::query()
            ->with('creator:id,name')
            ->when($category !== null, fn ($query) => $query->where('category', $category))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($search !== null, fn ($query) => $query->where('title', 'like', '%'.addcslashes($search ?? '', '%_\\').'%'))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(config()->integer('knowledge.articles.per_page'))
            ->withQueryString();

        return Inertia::render('knowledge/Index', [
            'copy' => __('knowledge.index'),
            'articles' => collect($page->items())->map(fn (mixed $article): array => $this->summary($article))->values()->all(),
            'pagination' => [
                'current' => $page->currentPage(),
                'last' => $page->lastPage(),
                'total' => $page->total(),
                'previous' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
            'filters' => ['category' => $category, 'q' => $search, 'status' => $status?->value],
            'categories' => KbArticle::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->values()->all(),
            'can_manage' => $this->user($request)->can('create', KbArticle::class),
            'tenant_id' => $this->user($request)->tenant_id,
            'total' => KbArticle::query()->count(),
            'max' => config()->integer('knowledge.articles.max'),
        ]);
    }

    public function create(Request $request): Response
    {
        Gate::authorize('create', KbArticle::class);

        return $this->editor($request, null);
    }

    public function show(Request $request, KbArticle $article): Response
    {
        Gate::authorize('view', $article);

        return $this->editor($request, $article);
    }

    public function store(SaveArticleRequest $request, Articles $articles, TenantContext $context): RedirectResponse
    {
        $article = $articles->create($this->user($request), $this->tenant($context), $request->articleTitle(), $request->articleBody(), $request->articleCategory());

        return to_route('knowledge.show', $article)->with('status', __('knowledge.article.created'));
    }

    public function update(SaveArticleRequest $request, KbArticle $article, Articles $articles): RedirectResponse
    {
        $articles->update($this->user($request), $article, $request->articleTitle(), $request->articleBody(), $request->articleCategory());

        return back();
    }

    public function publish(Request $request, KbArticle $article, Articles $articles): RedirectResponse
    {
        Gate::authorize('publish', $article);

        $articles->publish($this->user($request), $article);

        return back()->with('status', __('knowledge.article.published'));
    }

    public function unpublish(Request $request, KbArticle $article, Articles $articles): RedirectResponse
    {
        Gate::authorize('publish', $article);

        $articles->unpublish($this->user($request), $article);

        return back()->with('status', __('knowledge.article.unpublished'));
    }

    public function reindex(Request $request, KbArticle $article, Reindexer $reindexer): RedirectResponse
    {
        Gate::authorize('publish', $article);

        $reindexer->reindex($article);

        return back()->with('status', __('knowledge.article.reindexing'));
    }

    public function destroy(Request $request, KbArticle $article, Articles $articles): RedirectResponse
    {
        Gate::authorize('delete', $article);

        $title = $article->title;
        $articles->delete($this->user($request), $article);

        return to_route('knowledge')->with('status', __('knowledge.article.deleted', ['title' => $title]));
    }

    private function editor(Request $request, ?KbArticle $article): Response
    {
        $user = $this->user($request);

        return Inertia::render('knowledge/Article', [
            'copy' => __('knowledge.article'),
            'article' => $article === null ? null : [
                'id' => $article->id,
                'title' => $article->title,
                'body' => $article->body,
                'category' => $article->category,
                'status' => $article->status->value,
                'index_status' => $article->index_status?->value,
                'updated_at' => $article->updated_at->toIso8601String(),
                'published_at' => $article->published_at?->toIso8601String(),
            ],
            'can_edit' => $article === null ? $user->can('create', KbArticle::class) : $user->can('update', $article),
            'tenant_id' => $user->tenant_id,
            'categories' => KbArticle::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category')->values()->all(),
            'limits' => [
                'title' => config()->integer('knowledge.articles.title_max'),
                'body' => config()->integer('knowledge.articles.body_max'),
                'category' => config()->integer('knowledge.articles.category_max'),
            ],
        ]);
    }

    /**
     * @return array{id: int, title: string, category: string|null, status: string, index_status: string|null, updated_at: string, author: string|null}
     */
    private function summary(mixed $article): array
    {
        abort_unless($article instanceof KbArticle, 500);

        return [
            'id' => $article->id,
            'title' => $article->title,
            'category' => $article->category,
            'status' => $article->status->value,
            'index_status' => $article->index_status?->value,
            'updated_at' => $article->updated_at->toIso8601String(),
            'author' => $article->creator?->name,
        ];
    }

    private function filter(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 160) : null;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return $user;
    }

    private function tenant(TenantContext $context): Tenant
    {
        $tenant = $context->current();
        abort_if($tenant === null, 404);

        return $tenant;
    }
}
