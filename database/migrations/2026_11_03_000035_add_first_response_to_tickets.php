<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            // Cuándo el equipo (o la IA) respondió por primera vez al cliente; sirve para medir el tiempo de primera respuesta.
            $table->timestamp('first_response_at')->nullable()->after('last_message_at');
            $table->index(['tenant_id', 'resolved_at']);
        });

        // Los tickets que ya existen: el primer mensaje del equipo o de la IA, sin contar el aviso fijo de «te atenderá una persona».
        $handoffs = DB::table('ai_runs')->select('message_id')->where('status', 'escalated')->whereNotNull('message_id');

        DB::table('tickets')->orderBy('id')->chunkById(500, function ($tickets) use ($handoffs): void {
            foreach ($tickets as $ticket) {
                $first = DB::table('ticket_messages')
                    ->where('ticket_id', $ticket->id)
                    ->whereIn('author', ['agent', 'ai'])
                    ->whereNotIn('id', $handoffs)
                    ->orderBy('id')
                    ->value('created_at');

                if ($first !== null) {
                    DB::table('tickets')->where('id', $ticket->id)->update(['first_response_at' => $first]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'resolved_at']);
            $table->dropColumn('first_response_at');
        });
    }
};
