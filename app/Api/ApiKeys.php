<?php

declare(strict_types=1);

namespace App\Api;

use App\Enums\AuditEvent;
use App\Models\ApiKey;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crea, rota y revoca las llaves de API. Una llave es `tk_{empresa}_{40 caracteres}`: la empresa va delante para poder
 * fijar el contexto antes de buscar la llave (la búsqueda ya sale con el filtro de empresa) y el resto son unos 238 bits
 * aleatorios. En la base solo queda su huella y un prefijo; la llave completa se devuelve una sola vez, aquí.
 */
class ApiKeys
{
    public const MAX_ACTIVE = 10;

    private const SECRET_LENGTH = 40;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{key: ApiKey, plain: string}
     *
     * @throws ValidationException
     */
    public function create(User $actor, Tenant $tenant, string $name, ApiPermission $permission, ?int $expiresInDays): array
    {
        $active = ApiKey::query()->whereNull('revoked_at')->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();

        if ($active >= self::MAX_ACTIVE) {
            throw ValidationException::withMessages(['name' => __('api.keys.errors.max', ['max' => self::MAX_ACTIVE])]);
        }

        $secret = Str::random(self::SECRET_LENGTH);
        $plain = 'tk_'.$tenant->id.'_'.$secret;

        $key = new ApiKey;
        $key->forceFill([
            'name' => $name,
            'prefix' => 'tk_'.$tenant->id.'_'.substr($secret, 0, 6),
            'key_hash' => self::hash($plain),
            'permission' => $permission,
            'created_by' => $actor->id,
            'expires_at' => $expiresInDays === null ? null : now()->addDays($expiresInDays),
        ])->save();

        $this->audit->record(AuditEvent::ApiKeyCreated, $actor->id, ['credential_id' => $key->id, 'permission' => $permission->value]);

        return ['key' => $key, 'plain' => $plain];
    }

    /**
     * Crea una llave nueva con los mismos ajustes. La anterior sigue activa hasta que se revoque: así quien la usa puede
     * cambiarla sin cortes y decide cuándo apagar la vieja.
     *
     * @return array{key: ApiKey, plain: string}
     *
     * @throws ValidationException
     */
    public function rotate(User $actor, Tenant $tenant, ApiKey $old): array
    {
        $remaining = $old->expires_at === null ? null : max(1, (int) ceil(now()->diffInDays($old->expires_at, false)));
        $created = $this->create($actor, $tenant, $old->name, $old->permission, $remaining);

        $this->audit->record(AuditEvent::ApiKeyRotated, $actor->id, ['credential_id' => $old->id, 'new_credential_id' => $created['key']->id]);

        return $created;
    }

    public function revoke(User $actor, ApiKey $key): void
    {
        if ($key->revoked_at !== null) {
            return;
        }

        $key->forceFill(['revoked_at' => now()])->save();

        $this->audit->record(AuditEvent::ApiKeyRevoked, $actor->id, ['credential_id' => $key->id]);
    }

    /**
     * La empresa y la huella de una llave recibida; nulo si no tiene la forma exacta (así nada raro llega a la consulta).
     *
     * @return array{tenant_id: int, hash: string}|null
     */
    public static function parse(?string $plain): ?array
    {
        if ($plain === null || preg_match('/\Atk_([1-9]\d{0,17})_([A-Za-z0-9]{'.self::SECRET_LENGTH.'})\z/', $plain, $parts) !== 1) {
            return null;
        }

        return ['tenant_id' => (int) $parts[1], 'hash' => self::hash($plain)];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
