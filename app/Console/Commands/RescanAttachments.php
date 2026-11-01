<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Attachments\AttachmentStatus;
use App\Jobs\ScanAttachment;
use App\Models\Tenant;
use App\Models\TicketAttachment;
use App\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Vuelve a encolar la revisión de los adjuntos que quedaron sin revisar o sin poder revisarse (por ejemplo, porque el
 * antivirus estuvo caído un rato). Es seguro repetirlo: la revisión solo actúa sobre los «por revisar».
 */
class RescanAttachments extends Command
{
    protected $signature = 'attachments:rescan {--minutes=5 : Solo los que llevan al menos tantos minutos sin revisarse}';

    protected $description = 'Vuelve a encolar la revisión de los adjuntos por revisar o que no se pudieron revisar';

    public function handle(TenantContext $context): int
    {
        $limit = now()->subMinutes(max(0, (int) $this->option('minutes')));
        $queued = 0;

        Tenant::query()->each(function (Tenant $tenant) use ($context, $limit, &$queued): void {
            $context->run($tenant, function () use ($limit, &$queued): void {
                $stale = TicketAttachment::query()
                    ->whereIn('status', [AttachmentStatus::Pending->value, AttachmentStatus::Failed->value])
                    ->whereNotNull('path')
                    ->where('created_at', '<=', $limit)
                    ->get();

                foreach ($stale as $attachment) {
                    // Un adjunto que se declaró «no revisado» vuelve a ponerse en la cola de revisión.
                    $attachment->forceFill(['status' => AttachmentStatus::Pending])->save();
                    ScanAttachment::dispatch($attachment->id);
                    $queued++;
                }
            });
        });

        $this->info("Revisiones encoladas: {$queued}.");

        return self::SUCCESS;
    }
}
