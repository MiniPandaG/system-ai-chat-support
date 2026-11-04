<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // La encuesta de satisfacción de un ticket y su respuesta (RF-36): una por ticket. El token del enlace no se guarda,
        // solo su huella. Mientras `answered_at` esté vacío y no haya caducado, se puede responder una sola vez.
        Schema::create('csat_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ticket_id')->unique()->constrained('tickets')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('sent_at');
            $table->timestamp('expires_at');
            $table->timestamp('answered_at')->nullable();
            // Por dónde respondió: «email» (el enlace) o «widget».
            $table->string('channel', 10)->nullable();
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'answered_at']);
            $table->index(['tenant_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csat_responses');
    }
};
