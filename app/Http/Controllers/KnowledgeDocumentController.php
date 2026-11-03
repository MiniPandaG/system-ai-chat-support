<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Files\FileRejected;
use App\Http\Requests\SaveDocumentRequest;
use App\Http\Requests\UploadDocumentRequest;
use App\Knowledge\Documents;
use App\Knowledge\DocumentStatus;
use App\Knowledge\Reindexer;
use App\Models\KbDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Los documentos de la base de conocimiento. Administradores y agentes los leen; solo los administradores los suben,
 * editan, publican y borran (KbDocumentPolicy). Se resuelven con el filtro automático de empresa: uno de otra empresa
 * da 404. Un documento nunca se publica solo: pasa por la revisión de una persona.
 */
class KnowledgeDocumentController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', KbDocument::class);

        $page = KbDocument::query()
            ->with('creator:id,name')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(config()->integer('knowledge.documents.per_page'))
            ->withQueryString();

        return Inertia::render('knowledge/Documents', [
            'copy' => __('knowledge.documents'),
            'documents' => collect($page->items())->map(fn (mixed $document): array => $this->summary($document))->values()->all(),
            'pagination' => [
                'current' => $page->currentPage(),
                'last' => $page->lastPage(),
                'total' => $page->total(),
                'previous' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
            'can_manage' => $this->user($request)->can('create', KbDocument::class),
            'total' => KbDocument::query()->count(),
            'max' => config()->integer('knowledge.documents.max'),
            'max_bytes' => config()->integer('knowledge.documents.max_bytes'),
            'tenant_id' => $this->user($request)->tenant_id,
        ]);
    }

    public function show(Request $request, KbDocument $document): Response
    {
        Gate::authorize('view', $document);

        return Inertia::render('knowledge/Document', [
            'copy' => __('knowledge.document'),
            'document' => [
                ...$this->summary($document),
                'body' => $document->body,
                'published_at' => $document->published_at?->toIso8601String(),
            ],
            'can_edit' => $this->user($request)->can('update', $document),
            'limits' => [
                'title' => config()->integer('knowledge.documents.title_max'),
                'body' => config()->integer('knowledge.documents.text_max'),
                'category' => config()->integer('knowledge.documents.category_max'),
            ],
            'tenant_id' => $this->user($request)->tenant_id,
        ]);
    }

    public function store(UploadDocumentRequest $request, Documents $documents, TenantContext $context): RedirectResponse
    {
        try {
            $document = $documents->upload($this->user($request), $this->tenant($context), $request->document());
        } catch (FileRejected $rejected) {
            return back()->withErrors(['file' => $rejected->getMessage()]);
        }

        return to_route('knowledge.documents.show', $document)->with('status', __('knowledge.document.uploaded'));
    }

    public function update(SaveDocumentRequest $request, KbDocument $document, Documents $documents): RedirectResponse
    {
        $documents->update($this->user($request), $document, $request->documentTitle(), $request->documentBody(), $request->documentCategory());

        return back();
    }

    public function publish(Request $request, KbDocument $document, Documents $documents): RedirectResponse
    {
        Gate::authorize('publish', $document);

        $documents->publish($this->user($request), $document);

        return back()->with('status', __('knowledge.document.published'));
    }

    public function unpublish(Request $request, KbDocument $document, Documents $documents): RedirectResponse
    {
        Gate::authorize('publish', $document);

        $documents->unpublish($this->user($request), $document);

        return back()->with('status', __('knowledge.document.unpublished'));
    }

    public function reindex(Request $request, KbDocument $document, Reindexer $reindexer): RedirectResponse
    {
        Gate::authorize('publish', $document);

        $reindexer->reindex($document);

        return back()->with('status', __('knowledge.document.reindexing'));
    }

    public function destroy(Request $request, KbDocument $document, Documents $documents): RedirectResponse
    {
        Gate::authorize('delete', $document);

        $title = $document->title;
        $documents->delete($this->user($request), $document);

        return to_route('knowledge.documents')->with('status', __('knowledge.document.deleted', ['title' => $title]));
    }

    /**
     * @return array{id: int, title: string, original_name: string, type: string, size_bytes: int, pages: int|null, category: string|null, status: string, index_status: string|null, failure: string|null, updated_at: string, author: string|null}
     */
    private function summary(mixed $document): array
    {
        abort_unless($document instanceof KbDocument, 500);

        return [
            'id' => $document->id,
            'title' => $document->title,
            'original_name' => $document->original_name,
            'type' => $document->type,
            'size_bytes' => $document->size_bytes,
            'pages' => $document->pages,
            'category' => $document->category,
            'status' => $document->status->value,
            'index_status' => $document->index_status?->value,
            'failure' => $document->status === DocumentStatus::Failed ? $document->failure?->message() : null,
            'updated_at' => $document->updated_at->toIso8601String(),
            'author' => $document->creator?->name,
        ];
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
