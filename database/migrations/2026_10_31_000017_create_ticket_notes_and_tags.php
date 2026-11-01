<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mensajes y notas se mezclan en una sola conversación por hora de creación; con segundos enteros dos
        // entradas del mismo segundo no tendrían un orden fiable, así que ambas guardan microsegundos.
        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->timestamp('created_at', 6)->nullable()->change();
            $table->timestamp('updated_at', 6)->nullable()->change();
        });

        // Las notas internas viven aparte de los mensajes: ninguna consulta de mensajes puede devolverlas por error.
        Schema::create('ticket_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps(6);

            $table->index(['ticket_id', 'id']);
            $table->index(['tenant_id', 'ticket_id']);
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 30);
            $table->string('tone', 20);
            $table->timestamps();

            // La colación de MySQL no distingue mayúsculas: «VIP» y «vip» son la misma etiqueta.
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('ticket_tag', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            $table->foreignId('tag_id')->constrained('tags')->restrictOnDelete();

            $table->primary(['ticket_id', 'tag_id']);
            $table->index(['tenant_id', 'tag_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('ticket_notes');

        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->timestamp('created_at')->nullable()->change();
            $table->timestamp('updated_at')->nullable()->change();
        });
    }
};
