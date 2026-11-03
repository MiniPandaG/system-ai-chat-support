<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Documentos subidos (texto o PDF). El archivo original solo vive en el disco mientras se revisa y se lee; al
        // terminar queda únicamente el texto extraído, que una persona revisa antes de publicarlo.
        Schema::create('kb_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('title', 160);
            $table->string('original_name', 100);
            $table->string('type', 3);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->unsignedSmallInteger('pages')->nullable();
            $table->string('category', 40)->nullable();
            $table->mediumText('body')->nullable();
            // «scanning», «extracting», «review», «published», «failed» o «blocked».
            $table->string('status', 12)->default('scanning');
            // Motivo de un fallo, de una lista cerrada; nunca el texto de un error.
            $table->string('failure', 30)->nullable();
            $table->string('disk', 30);
            $table->string('path', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'updated_at']);
        });

        // Fragmentos de lo publicado: cada uno pertenece a un artículo o a un documento (nunca a los dos) y se borra
        // con él. Aquí vive el texto; el índice vectorial del paso siguiente solo guarda identificadores.
        Schema::create('kb_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('article_id')->nullable()->constrained('kb_articles')->cascadeOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('kb_documents')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kb_chunks');
        Schema::dropIfExists('kb_documents');
    }
};
