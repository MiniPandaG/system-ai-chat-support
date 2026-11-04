<?php

declare(strict_types=1);

namespace App\Ai\Providers;

/** Por qué falló una llamada a un proveedor. Lista cerrada: es lo único que se guarda del fallo. */
enum ProviderError: string
{
    case NotConfigured = 'not_configured';
    case Connection = 'connection';
    case Timeout = 'timeout';
    case RateLimited = 'rate_limited';
    case Auth = 'auth';
    case Rejected = 'rejected';
    case Server = 'server';
    case InvalidResponse = 'invalid_response';
    case TooLarge = 'too_large';
}
