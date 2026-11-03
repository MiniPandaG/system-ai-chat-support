<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Api\ApiKeys;
use App\Http\Requests\StoreApiKeyRequest;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Las llaves de API de la empresa. La llave completa solo se entrega una vez, en la sesión del administrador que la crea
 * o la rota (un dato de un solo uso que desaparece al recargar); la lista nunca la trae. Las llaves se buscan con el
 * filtro automático de empresa: la de otra empresa da 404.
 */
class ApiKeyController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('manage', ApiKey::class);

        $created = $request->session()->pull('new_api_key');

        return Inertia::render('settings/ApiKeys', [
            'copy' => __('api.keys'),
            'keys' => ApiKey::query()->orderBy('revoked_at')->orderByDesc('id')->get()
                ->map(static fn (ApiKey $key): array => [
                    'id' => $key->id,
                    'name' => $key->name,
                    'prefix' => $key->prefix,
                    'permission' => $key->permission->value,
                    'created_at' => $key->created_at->toIso8601String(),
                    'last_used_at' => $key->last_used_at?->toIso8601String(),
                    'expires_at' => $key->expires_at?->toIso8601String(),
                    'status' => $key->revoked_at !== null ? 'revoked' : ($key->isUsable() ? 'active' : 'expired'),
                ])->all(),
            'created' => is_array($created) ? $created : null,
            'max' => ApiKeys::MAX_ACTIVE,
        ]);
    }

    public function store(StoreApiKeyRequest $request, ApiKeys $keys, TenantContext $context): RedirectResponse
    {
        $created = $keys->create($this->user($request), $this->tenant($context), $request->keyName(), $request->apiPermission(), $request->expiresInDays());

        return back()->with('new_api_key', ['name' => $created['key']->name, 'value' => $created['plain']]);
    }

    public function rotate(Request $request, ApiKey $apiKey, ApiKeys $keys, TenantContext $context): RedirectResponse
    {
        Gate::authorize('manage', ApiKey::class);

        $created = $keys->rotate($this->user($request), $this->tenant($context), $apiKey);

        return back()->with('new_api_key', ['name' => $created['key']->name, 'value' => $created['plain']]);
    }

    public function destroy(Request $request, ApiKey $apiKey, ApiKeys $keys): RedirectResponse
    {
        Gate::authorize('manage', ApiKey::class);

        $name = $apiKey->name;
        $keys->revoke($this->user($request), $apiKey);

        return back()->with('status', __('api.keys.revoked', ['name' => $name]));
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
