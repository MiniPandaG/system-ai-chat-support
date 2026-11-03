<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Api\Presenters;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Quién puede atender tickets (administradores y agentes activos): sus identificadores son los que se usan en `assigned_to`.
 * Solo nombre y rol; nunca correos ni nada de la cuenta.
 */
class AgentController extends Controller
{
    public function index(TenantContext $context): JsonResponse
    {
        $tenantId = $context->id();
        abort_if($tenantId === null, 401);

        $agents = User::query()->inTenant($tenantId)->whereNull('disabled_at')->whereIn('role', [Role::Admin->value, Role::Agent->value])->orderBy('name')->get();

        return response()->json(['data' => $agents->map(Presenters::agent(...))->all()]);
    }
}
