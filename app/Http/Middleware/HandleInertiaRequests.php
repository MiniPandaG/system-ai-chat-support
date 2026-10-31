<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Plantilla raíz que se carga en la primera visita.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'flash' => [
                'status' => fn (): ?string => is_string($status = $request->session()->get('status')) ? $status : null,
                // Códigos de recuperación recién emitidos: se entregan una sola vez.
                'recovery_codes' => fn (): ?array => is_array($codes = $request->session()->get('recovery_codes')) ? array_values($codes) : null,
            ],
        ];
    }
}
