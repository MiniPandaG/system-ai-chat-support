<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Event;

/**
 * Lleva la empresa a los jobs: al encolar guarda tenant_id en la carga y, al ejecutar, lo restablece y luego
 * devuelve el contexto anterior (en la cola síncrona el job corre dentro de la petición).
 */
class TenantJobContext
{
    /** @var array<int, int|null> */
    private array $previous = [];

    public function register(): void
    {
        Queue::createPayloadUsing(fn (): array => ['tenant_id' => $this->context()->id()]);

        Event::listen(JobProcessing::class, $this->restore(...));
        Event::listen(JobProcessed::class, $this->release(...));
        Event::listen(JobExceptionOccurred::class, $this->release(...));
        Event::listen(JobFailed::class, $this->release(...));
    }

    public function restore(JobProcessing $event): void
    {
        $this->previous[spl_object_id($event->job)] = $this->context()->id();

        $tenantId = $event->job->payload()['tenant_id'] ?? null;

        $this->context()->set(is_int($tenantId) ? Tenant::query()->find($tenantId) : null);
    }

    public function release(JobProcessed|JobExceptionOccurred|JobFailed $event): void
    {
        $key = spl_object_id($event->job);

        if (! array_key_exists($key, $this->previous)) {
            return;
        }

        $previousId = $this->previous[$key];
        unset($this->previous[$key]);

        $this->context()->set($previousId === null ? null : Tenant::query()->find($previousId));
    }

    /**
     * Se resuelve en cada uso: el contexto es de ámbito y este objeto vive tanto como la aplicación.
     */
    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }
}
