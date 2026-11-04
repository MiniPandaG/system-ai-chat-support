<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Attachments\AttachmentStatus;
use App\Enums\Role;
use App\Models\Customer;
use App\Models\Tag;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\TicketNote;
use App\Models\TicketView;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tickets\MessageAuthor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Deja datos de una empresa en todas las tablas que guardan algo suyo, con un archivo real en el disco. Sirve para
 * demostrar que el borrado y la exportación alcanzan todo lo que una empresa tiene, y que no tocan a otra.
 */
final class TenantFootprint
{
    /**
     * @return array{admin: User, agent: User, ticket: Ticket, attachment: TicketAttachment, attachmentPath: string}
     */
    public static function seed(Tenant $tenant): array
    {
        $admin = User::factory()->forTenant($tenant, Role::Admin)->create();
        $agent = User::factory()->forTenant($tenant, Role::Agent)->create();
        $now = now();
        $id = $tenant->id;

        // Sesión abierta y enlace de recuperación del equipo: también son datos de la empresa.
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $admin->id, 'payload' => 'x', 'last_activity' => $now->timestamp]);
        DB::table('password_reset_tokens')->insert(['email' => $agent->email, 'token' => hash('sha256', Str::random(20)), 'created_at' => $now]);

        $result = app(TenantContext::class)->run($tenant, function () use ($admin, $agent, $now): array {
            $customer = Customer::factory()->create(['email' => 'cliente-'.Str::lower(Str::random(6)).'@ejemplo.com']);
            $ticket = Ticket::factory()->forCustomer($customer)->create(['number' => 1, 'assigned_to' => $agent->id, 'created_by' => $admin->id]);
            $message = TicketMessage::factory()->forTicket($ticket)->create(['author' => MessageAuthor::Customer]);
            TicketNote::factory()->forTicket($ticket)->writtenBy($agent)->create();
            $tag = Tag::factory()->create();
            $ticket->tags()->attach($tag->id, ['tenant_id' => $ticket->tenant_id]);
            TicketView::factory()->create(['user_id' => $agent->id]);

            $path = "tenants/{$ticket->tenant_id}/attachments/".Str::lower(Str::random(26));
            Storage::disk(config()->string('files.disk'))->put($path, 'contenido del adjunto');
            $attachment = TicketAttachment::factory()->forMessage($message)->status(AttachmentStatus::Clean)->create([
                'uploaded_by' => $agent->id,
                'path' => $path,
                'original_name' => 'factura de ejemplo.pdf',
            ]);

            DB::table('ticket_reads')->insert(['tenant_id' => $ticket->tenant_id, 'ticket_id' => $ticket->id, 'user_id' => $agent->id, 'last_read_at' => $now]);

            return ['ticket' => $ticket, 'attachment' => $attachment, 'attachmentPath' => $path, 'tag' => $tag];
        });

        $ticket = $result['ticket'];
        $stamp = ['created_at' => $now, 'updated_at' => $now];

        $threadId = DB::table('mail_threads')->insertGetId(['tenant_id' => $id, 'ticket_id' => $ticket->id, 'token' => 'cifrado', 'token_hash' => hash('sha256', Str::random(20)), 'created_at' => $now]);
        DB::table('mail_messages')->insert(['tenant_id' => $id, 'ticket_id' => $ticket->id, 'mail_thread_id' => $threadId, 'direction' => 'out', 'kind' => 'reply', 'message_id' => 'm-'.Str::random(8), 'status' => 'sent', ...$stamp]);
        DB::table('widget_sessions')->insert(['id' => Str::lower((string) Str::ulid()), 'tenant_id' => $id, 'customer_id' => $ticket->customer_id, 'ticket_id' => $ticket->id, 'token_hash' => hash('sha256', Str::random(20)), 'last_seen_at' => $now, 'expires_at' => $now->copy()->addDay(), 'created_at' => $now]);
        DB::table('widget_origins')->insert(['tenant_id' => $id, 'origin' => 'https://tienda-ejemplo.com', ...$stamp]);
        DB::table('csat_responses')->insert(['tenant_id' => $id, 'ticket_id' => $ticket->id, 'token_hash' => hash('sha256', Str::random(20)), 'sent_at' => $now, 'expires_at' => $now->copy()->addDays(14), 'answered_at' => $now, 'channel' => 'email', 'score' => 5, 'comment' => 'Muy buena atención', ...$stamp]);

        $keyId = DB::table('api_keys')->insertGetId(['tenant_id' => $id, 'name' => 'Integración', 'prefix' => 'gk_'.$id.'_abcd', 'key_hash' => hash('sha256', Str::random(20)), 'permission' => 'read', 'created_by' => $admin->id, ...$stamp]);
        DB::table('api_idempotency')->insert(['tenant_id' => $id, 'api_key_id' => $keyId, 'key_hash' => hash('sha256', Str::random(20)), 'request_hash' => hash('sha256', Str::random(20)), 'created_at' => $now, 'expires_at' => $now->copy()->addDay()]);
        DB::table('invitations')->insert(['tenant_id' => $id, 'email' => 'nuevo@ejemplo.com', 'role' => 'agent', 'token_hash' => hash('sha256', Str::random(20)), 'invited_by' => $admin->id, 'expires_at' => $now->copy()->addDays(3), ...$stamp]);

        $logoPath = "tenants/{$id}/logo/".Str::lower(Str::random(26));
        Storage::disk(config()->string('files.disk'))->put($logoPath, 'png');
        $logoId = Str::lower((string) Str::ulid());
        DB::table('files')->insert(['id' => $logoId, 'tenant_id' => $id, 'purpose' => 'logo', 'disk' => config()->string('files.disk'), 'path' => $logoPath, 'mime' => 'image/png', 'size' => 3, 'width' => 128, 'height' => 128, 'sha256' => hash('sha256', 'png'), ...$stamp]);
        DB::table('tenant_branding')->insert(['tenant_id' => $id, 'logo_file_id' => $logoId, ...$stamp]);

        $articleId = DB::table('kb_articles')->insertGetId(['tenant_id' => $id, 'title' => 'Cambios y devoluciones', 'body' => 'Aceptamos cambios en 30 días.', 'status' => 'published', ...$stamp]);
        $documentPath = "tenants/{$id}/knowledge/".Str::lower(Str::random(26));
        Storage::disk(config()->string('files.disk'))->put($documentPath, 'documento');
        $documentId = DB::table('kb_documents')->insertGetId(['tenant_id' => $id, 'title' => 'Manual', 'original_name' => 'manual.pdf', 'type' => 'pdf', 'size_bytes' => 9, 'sha256' => hash('sha256', 'documento'), 'disk' => config()->string('files.disk'), 'path' => $documentPath, 'body' => 'texto', 'status' => 'published', ...$stamp]);
        DB::table('kb_chunks')->insert(['tenant_id' => $id, 'article_id' => $articleId, 'document_id' => null, 'position' => 0, 'body' => 'Aceptamos cambios en 30 días.', 'created_at' => $now]);
        DB::table('kb_chunks')->insert(['tenant_id' => $id, 'article_id' => null, 'document_id' => $documentId, 'position' => 0, 'body' => 'texto', 'created_at' => $now]);
        DB::table('kb_context')->insert(['tenant_id' => $id, 'body' => 'Vendemos zapatos.', ...$stamp]);

        DB::table('ai_settings')->insert(['tenant_id' => $id, ...$stamp]);
        DB::table('ai_usage_daily')->insert(['tenant_id' => $id, 'day' => $now->toDateString(), 'calls' => 1, ...$stamp]);
        $messageId = TicketMessage::query()->withoutGlobalScopes()->where('ticket_id', $ticket->id)->value('id');
        $runId = DB::table('ai_runs')->insertGetId(['tenant_id' => $id, 'ticket_id' => $ticket->id, 'trigger_message_id' => $messageId, 'status' => 'done', 'reason' => 'answered', ...$stamp]);
        DB::table('ai_calls')->insert(['tenant_id' => $id, 'run_id' => $runId, 'purpose' => 'reply', 'provider' => 'fake', 'catalog_id' => 'fake', 'model' => 'fake', 'status' => 'ok', 'created_at' => $now]);

        return ['admin' => $admin, 'agent' => $agent, 'ticket' => $ticket, 'attachment' => $result['attachment'], 'attachmentPath' => $result['attachmentPath']];
    }
}
