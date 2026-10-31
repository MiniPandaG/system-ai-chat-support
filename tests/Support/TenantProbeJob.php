<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Job de prueba que anota con qué empresa en contexto se ejecutó.
 */
class TenantProbeJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @var list<int|null> */
    public static array $seen = [];

    public function handle(TenantContext $context): void
    {
        self::$seen[] = $context->id();
    }
}
