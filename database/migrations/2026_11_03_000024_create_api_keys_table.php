<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Las llaves de API de una empresa. De la llave solo se guarda su huella SHA-256 y un prefijo para reconocerla en la
        // lista; la llave completa se muestra una sola vez al crearla y no vuelve a existir en ningún sitio.
        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->string('name', 60);
            $table->string('prefix', 32);
            $table->char('key_hash', 64)->unique();
            // El nivel de acceso: «read», «write» (incluye leer) o «admin» (incluye escribir).
            $table->string('permission', 5);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
