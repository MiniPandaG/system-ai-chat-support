<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // En qué va la indexación de lo publicado: «pending» (en cola o en curso), «indexed» o «failed». Lo que no está
        // publicado no se indexa y lo deja en nulo.
        foreach (['kb_articles', 'kb_documents'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('index_status', 10)->nullable()->after('published_at');
                $table->timestamp('indexed_at')->nullable()->after('index_status');
            });
        }
    }

    public function down(): void
    {
        foreach (['kb_articles', 'kb_documents'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn(['index_status', 'indexed_at']);
            });
        }
    }
};
