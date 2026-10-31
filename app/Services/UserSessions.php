<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sesiones abiertas de una cuenta, guardadas por Laravel en la tabla sessions. El identificador real de una sesión
 * no sale del servidor: la interfaz trabaja con un código derivado con la clave de la aplicación.
 */
class UserSessions
{
    public function __construct(private readonly DeviceDescriber $devices) {}

    /**
     * Solo las sesiones que todavía no caducaron por inactividad, la actual primero y luego por actividad reciente.
     *
     * @return list<array{handle: string, device: string, ip: string|null, last_activity: string, last_activity_label: string, current: bool}>
     */
    public function list(User $user, string $currentId): array
    {
        $rows = DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('last_activity', '>=', $this->oldestActive())
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity']);

        $sessions = [];

        foreach ($rows as $row) {
            $id = is_scalar($row->id) ? (string) $row->id : '';
            $lastActivity = Carbon::createFromTimestamp(is_numeric($row->last_activity) ? (int) $row->last_activity : 0);

            $sessions[] = [
                'handle' => self::handle($id),
                'device' => $this->devices->describe(is_string($row->user_agent) ? $row->user_agent : null),
                'ip' => is_string($row->ip_address) ? $row->ip_address : null,
                'last_activity' => $lastActivity->toIso8601String(),
                'last_activity_label' => $lastActivity->diffForHumans(),
                'current' => hash_equals($currentId, $id),
            ];
        }

        usort($sessions, fn (array $a, array $b): int => (int) $b['current'] <=> (int) $a['current']);

        return $sessions;
    }

    /**
     * Cierra una sesión ajena de la cuenta. Devuelve falso si el código no corresponde a ninguna sesión suya o si es
     * la sesión actual (esa se cierra con el cierre de sesión normal).
     */
    public function revoke(User $user, string $handle, string $currentId): bool
    {
        $ids = DB::table('sessions')->where('user_id', $user->getKey())->pluck('id');

        foreach ($ids as $id) {
            if (! is_string($id)) {
                continue;
            }

            if (hash_equals(self::handle($id), $handle) && ! hash_equals($currentId, $id)) {
                return DB::table('sessions')->where('id', $id)->where('user_id', $user->getKey())->delete() > 0;
            }
        }

        return false;
    }

    public function revokeOthers(User $user, string $currentId): int
    {
        return DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentId)
            ->delete();
    }

    public function revokeAll(User $user): int
    {
        return DB::table('sessions')->where('user_id', $user->getKey())->delete();
    }

    public static function handle(string $sessionId): string
    {
        return hash_hmac('sha256', $sessionId, config()->string('app.key'));
    }

    private function oldestActive(): int
    {
        return time() - config()->integer('session.lifetime') * 60;
    }
}
