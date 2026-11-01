<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Orden por prioridad sin SQL crudo en la aplicación: MySQL calcula el rango y lo mantiene al día solo,
        // así que ninguna escritura puede dejarlo desincronizado de la prioridad.
        Schema::table('tickets', function (Blueprint $table): void {
            $table->unsignedTinyInteger('priority_rank')
                ->storedAs("case priority when 'urgent' then 4 when 'high' then 3 when 'normal' then 2 else 1 end")
                ->after('priority');

            $table->index(['tenant_id', 'priority_rank', 'last_message_at']);
            $table->fullText('subject');
        });

        // La búsqueda de la bandeja mira el texto de los mensajes, el asunto y los datos del cliente.
        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->fullText('body');
        });

        Schema::table('customers', function (Blueprint $table): void {
            $table->fullText(['name', 'email']);
        });

        Schema::create('ticket_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 60);
            $table->boolean('shared')->default(false);
            // Solo la combinación de filtros ya normalizada por la aplicación; nunca consultas ni SQL.
            $table->json('filters');
            $table->timestamps();

            $table->index(['tenant_id', 'shared']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_views');

        Schema::table('customers', function (Blueprint $table): void {
            $table->dropFullText(['name', 'email']);
        });

        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->dropFullText(['body']);
        });

        Schema::table('tickets', function (Blueprint $table): void {
            $table->dropFullText(['subject']);
            $table->dropIndex(['tenant_id', 'priority_rank', 'last_message_at']);
            $table->dropColumn('priority_rank');
        });
    }
};
