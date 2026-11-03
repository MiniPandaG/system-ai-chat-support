<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Knowledge\Search;
use App\Knowledge\SearchResult;
use App\Knowledge\SourceKind;
use App\Knowledge\Vector\EmbeddingUnavailable;
use App\Knowledge\Vector\IndexUnavailable;
use App\Models\KbArticle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * «Probar la búsqueda»: lo que encontraría la IA con una pregunta, solo del conocimiento publicado de la empresa de quien
 * pregunta. Administradores y agentes. Responde JSON; un fallo del servicio de búsqueda se dice con un mensaje fijo, sin
 * detalles internos.
 */
class KnowledgeSearchController extends Controller
{
    public function __invoke(Request $request, Search $search): JsonResponse
    {
        Gate::authorize('viewAny', KbArticle::class);

        $max = config()->integer('knowledge.search.query_max');
        $query = $request->query('q');

        if (! is_string($query) || trim($query) === '') {
            return response()->json(['message' => __('knowledge.search.errors.query_required')], 422);
        }

        if (mb_strlen($query) > $max) {
            return response()->json(['message' => __('knowledge.search.errors.query_max', ['max' => $max])], 422);
        }

        try {
            $results = $search->search($query);
        } catch (EmbeddingUnavailable|IndexUnavailable) {
            return response()->json(['message' => __('knowledge.search.errors.unavailable')], 503);
        }

        return response()->json(['results' => array_map(fn (SearchResult $result): array => [
            'text' => $result->text,
            'score' => round(max(0.0, min(1.0, $result->score)), 2),
            'kind' => $result->kind->value,
            'title' => $result->sourceTitle,
            'category' => $result->category,
            'url' => $result->kind === SourceKind::Article ? "/knowledge/articles/{$result->sourceId}" : "/knowledge/documents/{$result->sourceId}",
        ], $results)]);
    }
}
