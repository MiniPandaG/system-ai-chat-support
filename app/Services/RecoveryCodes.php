<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Códigos de recuperación del 2FA. Se muestran una sola vez y en la base solo queda una huella HMAC de cada uno,
 * con la clave de la aplicación como pimienta: una copia de la base no basta para usarlos.
 */
final class RecoveryCodes
{
    public const COUNT = 10;

    /** Sin I, O, 0 ni 1, que se confunden al leerlos. */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * @return list<string>
     */
    public static function generate(): array
    {
        $codes = [];

        while (count($codes) < self::COUNT) {
            $code = self::randomGroup().'-'.self::randomGroup();
            $codes[$code] = $code;
        }

        return array_values($codes);
    }

    /**
     * Genera un juego nuevo, reemplaza al anterior y devuelve los códigos en claro para mostrarlos una vez.
     *
     * @return list<string>
     */
    public static function issue(User $user): array
    {
        $codes = self::generate();

        $user->forceFill([
            'two_factor_recovery_codes' => json_encode(array_map(self::hash(...), $codes), JSON_THROW_ON_ERROR),
        ])->save();

        return $codes;
    }

    public static function remaining(User $user): int
    {
        return count(self::hashesOf($user));
    }

    public static function matches(User $user, string $input): bool
    {
        $candidate = self::hash($input);
        $found = false;

        // Se recorren todas para no variar el tiempo según la posición del código.
        foreach (self::hashesOf($user) as $stored) {
            $found = hash_equals($stored, $candidate) || $found;
        }

        return $found;
    }

    /**
     * Gasta un código: queda fuera de la lista y no sirve una segunda vez, ni siquiera en dos peticiones a la vez.
     */
    public static function consume(User $user, string $input): bool
    {
        return DB::transaction(function () use ($user, $input): bool {
            $fresh = User::query()->whereKey($user->getKey())->lockForUpdate()->first();

            if ($fresh === null) {
                return false;
            }

            $candidate = self::hash($input);
            $remaining = [];
            $used = false;

            foreach (self::hashesOf($fresh) as $stored) {
                if (! $used && hash_equals($stored, $candidate)) {
                    $used = true;

                    continue;
                }

                $remaining[] = $stored;
            }

            if ($used) {
                $fresh->forceFill(['two_factor_recovery_codes' => json_encode($remaining, JSON_THROW_ON_ERROR)])->save();
                $user->forceFill(['two_factor_recovery_codes' => $fresh->two_factor_recovery_codes])->syncOriginal();
            }

            return $used;
        });
    }

    public static function normalize(string $input): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));
    }

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalize($code), config()->string('app.key'));
    }

    private static function randomGroup(): string
    {
        $group = '';

        for ($i = 0; $i < 8; $i++) {
            $group .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $group;
    }

    /**
     * @return list<string>
     */
    private static function hashesOf(User $user): array
    {
        $raw = $user->two_factor_recovery_codes;

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, is_string(...))) : [];
    }
}
