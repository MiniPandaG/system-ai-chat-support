<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Resume un user agent en «Navegador en Sistema». El user agent lo escribe el cliente, así que nunca se muestra
 * tal cual: solo salen nombres de una lista cerrada.
 */
class DeviceDescriber
{
    /** El orden importa: Edge y Opera también dicen «Chrome», y Chrome también dice «Safari». */
    private const BROWSERS = [
        'Edg/' => 'Edge',
        'OPR/' => 'Opera',
        'Firefox/' => 'Firefox',
        'Chrome/' => 'Chrome',
        'Safari/' => 'Safari',
    ];

    /** Android y iOS van antes que Linux y macOS porque sus user agents también los mencionan. */
    private const SYSTEMS = [
        'Android' => 'Android',
        'iPhone' => 'iOS',
        'iPad' => 'iOS',
        'Windows' => 'Windows',
        'Mac OS X' => 'macOS',
        'Linux' => 'Linux',
    ];

    public function describe(?string $userAgent): string
    {
        $agent = $userAgent ?? '';

        $browser = $this->firstMatch(self::BROWSERS, $agent);
        $system = $this->firstMatch(self::SYSTEMS, $agent);

        if ($browser === null && $system === null) {
            return __('security.sessions.unknown_device');
        }

        return __('security.sessions.device', [
            'browser' => $browser ?? __('security.sessions.unknown_browser'),
            'system' => $system ?? __('security.sessions.unknown_system'),
        ]);
    }

    /**
     * @param  array<string, string>  $vocabulary
     */
    private function firstMatch(array $vocabulary, string $agent): ?string
    {
        foreach ($vocabulary as $needle => $name) {
            if (str_contains($agent, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
