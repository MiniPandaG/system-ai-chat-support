<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_attachments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('ticket_message_id')->constrained('ticket_messages')->restrictOnDelete();
            // Quién lo subió; nulo cuando lo traiga un canal (chat web o correo) en vez de una persona del equipo.
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            // Solo para mostrarlo y como nombre de la descarga; nunca se usa para guardar el archivo.
            $table->string('original_name', 120);
            // El tipo real detectado en el contenido.
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->char('sha256', 64);
            $table->string('disk', 20);
            // Ruta generada por el sistema, sin extensión. Nula cuando se borró el archivo por contener una amenaza.
            $table->string('path', 255)->nullable();
            // pending (por revisar), clean (se puede descargar), infected (bloqueado) o failed (no se pudo revisar).
            $table->string('status', 12);
            $table->string('scan_signature', 100)->nullable();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamps();

            $table->index('ticket_message_id');
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
    }
};
