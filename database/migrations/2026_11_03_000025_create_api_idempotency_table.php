<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lo que ya respondió la API a una petición con «Idempotency-Key»: repetirla (por un reintento tras un corte) devuelve
        // la misma respuesta y no crea nada otra vez. La clave y la petición se guardan como huellas.
        Schema::create('api_idempotency', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->foreignId('api_key_id')->constrained('api_keys')->restrictOnDelete();
            $table->char('key_hash', 64);
            $table->char('request_hash', 64);
            // Nulo mientras la petición original sigue en curso.
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->string('location', 255)->nullable();
            $table->timestamp('created_at');
            $table->timestamp('expires_at');

            $table->unique(['api_key_id', 'key_hash']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency');
    }
};
