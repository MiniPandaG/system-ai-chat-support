<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Buscador de clientes de la empresa para abrir un ticket. Mínimo dos caracteres y diez resultados, para que no sirva
 * para recorrer la lista de clientes.
 */
class CustomerSearchController extends Controller
{
    private const MIN_LENGTH = 2;

    private const MAX_LENGTH = 100;

    private const MAX_RESULTS = 10;

    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Customer::class);

        // Un término que no es texto (por ejemplo q[]=a) se trata como vacío en vez de romper la consulta.
        $raw = $request->query('q');
        $term = is_string($raw) ? mb_substr(trim($raw), 0, self::MAX_LENGTH) : '';

        if (mb_strlen($term) < self::MIN_LENGTH) {
            return $this->respond([]);
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $customers = Customer::query()
            ->where(fn ($query) => $query->where('name', 'like', $like)->orWhere('email', 'like', $like))
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get(['id', 'name', 'email']);

        return $this->respond(array_values($customers->map(fn (Customer $customer): array => [
            'id' => $customer->id,
            'name' => $customer->name,
            'email' => $customer->email,
        ])->all()));
    }

    /**
     * @param  list<array{id: int, name: string, email: string|null}>  $data
     */
    private function respond(array $data): JsonResponse
    {
        return response()->json(['data' => $data])->header('Cache-Control', 'no-store');
    }
}
