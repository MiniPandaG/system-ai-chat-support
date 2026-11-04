<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una ejecución de la IA por mensaje del cliente que la dispara (RIA-01): qué fragmentos usó, con qué puntaje, con
        // qué confianza y por qué respondió o escaló. El índice único sobre el mensaje hace que un mismo mensaje no pueda
        // disparar dos respuestas aunque el trabajo se repita.
        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignId('trigger_message_id')->unique()->constrained('ticket_messages')->cascadeOnDelete();
            // Por dónde llegó el mensaje: «widget» o «email».
            $table->string('channel', 10)->nullable();
            // «shadow» (un humano aprueba) o «auto» (se envía sola).
            $table->string('mode', 6)->nullable();
            $table->string('status', 16);
            $table->string('reason', 24);
            $table->string('catalog_id', 40)->nullable();
            // Lo parecida que fue la mejor fuente (0 a 1) y la confianza que el código calculó (0 a 100).
            $table->decimal('top_score', 4, 3)->nullable();
            $table->unsignedTinyInteger('confidence')->nullable();
            // Los fragmentos citados: {kind, id, title, chunk_id}. Nunca el texto del cliente.
            $table->json('sources')->nullable();
            // La respuesta de la IA (borrador o ya enviada), ya validada y limpia.
            $table->text('body')->nullable();
            $table->foreignId('message_id')->nullable()->constrained('ticket_messages')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('edited')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'ticket_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_runs');
    }
};
