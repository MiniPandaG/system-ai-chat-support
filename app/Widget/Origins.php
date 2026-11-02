<?php

declare(strict_types=1);

namespace App\Widget;

use App\Models\Tenant;
use App\Models\WidgetOrigin;
use App\Tenancy\TenantContext;

/**
 * Los sitios donde una empresa permite su widget. Un origen es esquema, servidor y puerto, exactamente como los envía el
 * navegador: sin ruta, sin comodines, sin credenciales. Solo https, salvo `localhost` y `127.0.0.1` para probar en local.
 */
class Origins
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * El origen en su forma canónica, o nulo si no es un origen admisible.
     */
    public static function normalize(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x20\x7F\\\\]/', $value) === 1) {
            return null;
        }

        $parts = parse_url($value);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $local = in_array($host, ['localhost', '127.0.0.1'], true);

        if (! ($scheme === 'https' || ($scheme === 'http' && $local))) {
            return null;
        }

        if (! $local && preg_match('/\A(?=.{1,253}\z)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/', $host) !== 1) {
            return null;
        }

        $port = $parts['port'] ?? null;
        $default = $scheme === 'https' ? 443 : 80;

        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        return $scheme.'://'.$host.($port !== null && $port !== $default ? ':'.$port : '');
    }

    /**
     * Los orígenes que la empresa permite hoy.
     *
     * @return list<string>
     */
    public function for(Tenant $tenant): array
    {
        return $this->context->run($tenant, fn (): array => array_values(
            WidgetOrigin::query()->orderBy('origin')->get()->map(static fn (WidgetOrigin $row): string => $row->origin)->all(),
        ));
    }

    /** El widget de la empresa está activo mientras tenga al menos un origen. */
    public function isEnabled(Tenant $tenant): bool
    {
        return $this->context->run($tenant, static fn (): bool => WidgetOrigin::query()->exists());
    }
}
