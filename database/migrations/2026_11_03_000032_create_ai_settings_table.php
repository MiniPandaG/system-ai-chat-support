<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una fila por empresa. Sin fila, la IA está apagada en todos los canales.
        Schema::create('ai_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained('tenants')->restrictOnDelete();
            // El modelo del catálogo que redacta las respuestas; nulo = el primero disponible.
            $table->string('reply_model', 40)->nullable();
            // Qué hace la IA en cada canal: apagada o borrador para que una persona lo revise.
            $table->string('widget_mode', 10)->default('off');
            $table->string('email_mode', 10)->default('off');
            $table->boolean('identify_as_ai')->default(true);
            $table->boolean('require_knowledge')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_settings');
    }
};
