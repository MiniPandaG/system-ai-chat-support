<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // La llave pública del widget: identifica a la empresa en el script incrustado. No es un secreto (va en la página
        // del cliente), pero no se puede adivinar ni deducir del identificador de la empresa.
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('widget_key', 32)->nullable()->unique()->after('name');
        });

        foreach (DB::table('tenants')->pluck('id') as $tenantId) {
            DB::table('tenants')->where('id', $tenantId)->update(['widget_key' => Str::random(32)]);
        }

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('widget_key', 32)->nullable(false)->change();
        });

        // Los sitios donde la empresa permite incrustar el widget. Sin ninguno, el canal no está activo.
        Schema::create('widget_origins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('origin', 255);
            $table->timestamps();

            $table->unique(['tenant_id', 'origin']);
        });

        // La sesión anónima de quien escribe desde el widget. El token solo existe en el navegador del visitante; aquí se
        // guarda su huella. Nada de IP ni de agente de usuario: los límites de abuso viven en la caché.
        Schema::create('widget_sessions', function (Blueprint $table): void {
            $table->char('id', 26)->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained('tickets')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('last_seen_at');
            $table->timestamp('expires_at');
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'expires_at']);
            $table->index('ticket_id');
        });

        // Quién y con qué identificador escribió un mensaje del widget: el identificador lo genera el navegador y evita que
        // reenviar un mensaje (por una desconexión) lo duplique.
        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->char('widget_session_id', 26)->nullable()->after('user_id');
            $table->char('client_id', 36)->nullable()->after('widget_session_id');

            $table->foreign('widget_session_id')->references('id')->on('widget_sessions')->nullOnDelete();
            $table->unique(['widget_session_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('ticket_messages', function (Blueprint $table): void {
            $table->dropForeign(['widget_session_id']);
            $table->dropUnique(['widget_session_id', 'client_id']);
            $table->dropColumn(['widget_session_id', 'client_id']);
        });

        Schema::dropIfExists('widget_sessions');
        Schema::dropIfExists('widget_origins');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropUnique(['widget_key']);
            $table->dropColumn('widget_key');
        });
    }
};
