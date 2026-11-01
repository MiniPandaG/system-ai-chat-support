<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contador del número de ticket de cada empresa; se incrementa con la fila de la empresa bloqueada.
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedInteger('last_ticket_number')->default(0)->after('plan_id');
        });

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('subject', 150);
            $table->string('status', 20);
            $table->string('priority', 20);
            $table->string('channel', 20);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('last_message_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'last_message_at']);
            $table->index(['tenant_id', 'assigned_to', 'last_message_at']);
            $table->index(['tenant_id', 'created_at']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('ticket_id')->constrained('tickets')->restrictOnDelete();
            // Quién habla: el cliente o un agente. El primer mensaje es la descripción con la que se abrió el ticket.
            $table->string('author', 20);
            // El miembro del equipo que lo escribió o lo registró en nombre del cliente.
            $table->foreignId('user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['ticket_id', 'id']);
            $table->index(['tenant_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('last_ticket_number');
        });
    }
};
