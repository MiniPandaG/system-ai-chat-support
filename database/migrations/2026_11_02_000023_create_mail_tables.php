<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // El hilo de correo de un ticket: lo que une las respuestas que llegan con el ticket. El token va dentro de la
        // dirección de respuesta y del identificador de cada correo que sale; aquí se guarda cifrado (para poder volver a
        // ponerlo en cada envío) y con su huella (para encontrarlo al llegar una respuesta).
        Schema::create('mail_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->text('token');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('created_at')->nullable();

            $table->unique('ticket_id');
        });

        // Cada correo que sale o que entra, sin su contenido (el texto vive en el mensaje del ticket). Sirve para no
        // procesar dos veces lo mismo y para saber por qué se rechazó un correo.
        Schema::create('mail_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('mail_thread_id')->constrained('mail_threads')->restrictOnDelete();
            $table->string('direction', 3);
            $table->string('kind', 20);
            // El Message-ID del correo: único por sentido, así un correo repetido se reconoce y una respuesta puede citar al anterior.
            $table->string('message_id', 255);
            $table->foreignId('ticket_message_id')->nullable()->constrained('ticket_messages')->nullOnDelete();
            $table->string('status', 12);
            $table->string('reason', 30)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(['direction', 'message_id']);
            // Un mismo mensaje del ticket no genera dos correos del mismo tipo.
            $table->unique(['ticket_message_id', 'direction', 'kind']);
            $table->index(['tenant_id', 'ticket_id']);
        });

        Schema::create('mail_rejections', function (Blueprint $table): void {
            $table->id();
            // Un correo que no llegó a ningún hilo (sin token, token falso o de una empresa inexistente) no tiene empresa:
            // solo se cuenta por motivo y día, sin guardar ni el remitente ni el asunto.
            $table->string('reason', 30);
            $table->date('day');
            $table->unsignedInteger('total')->default(0);

            $table->unique(['reason', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_rejections');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_threads');
    }
};
