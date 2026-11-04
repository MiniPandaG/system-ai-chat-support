<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una fila por llamada a un modelo (RF-38): quién, con qué modelo, cuántos tokens y cuánto costó. Nunca guarda el
        // texto enviado ni el recibido. El costo va en millonésimas de dólar para sumar sin decimales.
        Schema::create('ai_calls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->string('purpose', 20);
            $table->string('provider', 20);
            $table->string('catalog_id', 40);
            $table->string('model', 80);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_micro')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            // «ok» o «error»; el motivo de un error es de una lista cerrada, nunca el texto del proveedor.
            $table->string('status', 8);
            $table->string('error_code', 24)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
        });

        // El consumo de cada día por empresa (en UTC): lo que mira el tope de gasto antes de cada llamada.
        Schema::create('ai_usage_daily', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->restrictOnDelete();
            $table->date('day');
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cost_micro')->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_daily');
        Schema::dropIfExists('ai_calls');
    }
};
