<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_branding', function (Blueprint $table): void {
            $table->id();
            // Una fila por empresa; sin fila, la empresa usa la marca por defecto.
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->restrictOnDelete();
            $table->char('logo_file_id', 26)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_branding');
    }
};
