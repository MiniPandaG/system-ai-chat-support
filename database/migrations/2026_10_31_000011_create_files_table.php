<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table): void {
            // ULID: no es secuencial, así que un identificador no se adivina sumando uno.
            $table->ulid('id')->primary();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('purpose', 30);
            $table->string('disk', 30);
            // Ruta generada por el sistema; el nombre que trae el archivo nunca se guarda.
            $table->string('path')->unique();
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
