<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 30)->unique();
            $table->string('name', 60);
            // Solo uno es el de por defecto: el que recibe una empresa nueva.
            $table->boolean('is_default')->default(false);
            // Un plan inactivo no se asigna a empresas nuevas, pero las que ya lo tienen lo conservan.
            $table->boolean('is_active')->default(true);
            // Límites: nulo significa sin límite. Cada módulo que mida un recurso nuevo añade su columna.
            $table->unsignedInteger('max_agents')->nullable();
            $table->unsignedInteger('max_channels')->nullable();
            $table->unsignedInteger('max_tickets_per_month')->nullable();
            $table->unsignedInteger('ai_credits_per_month')->nullable();
            $table->timestamps();
        });

        // El catálogo inicial es parte del producto, no datos de ejemplo. Se puede cambiar sin tocar el código.
        $now = now();
        DB::table('plans')->insert([
            ['slug' => 'inicial', 'name' => 'Inicial', 'is_default' => true, 'is_active' => true, 'max_agents' => 2, 'max_channels' => 1, 'max_tickets_per_month' => 100, 'ai_credits_per_month' => 50, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'equipo', 'name' => 'Equipo', 'is_default' => false, 'is_active' => true, 'max_agents' => 5, 'max_channels' => 3, 'max_tickets_per_month' => 1000, 'ai_credits_per_month' => 500, 'created_at' => $now, 'updated_at' => $now],
            ['slug' => 'negocio', 'name' => 'Negocio', 'is_default' => false, 'is_active' => true, 'max_agents' => 15, 'max_channels' => 5, 'max_tickets_per_month' => 5000, 'ai_credits_per_month' => 2500, 'created_at' => $now, 'updated_at' => $now],
        ]);

        $defaultId = DB::table('plans')->where('is_default', true)->value('id');
        assert(is_numeric($defaultId));

        Schema::table('tenants', function (Blueprint $table): void {
            $table->foreignId('plan_id')->nullable()->after('name')->constrained('plans')->restrictOnDelete();
        });

        // Las empresas que ya existían reciben el plan por defecto.
        DB::table('tenants')->whereNull('plan_id')->update(['plan_id' => $defaultId]);

        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('plan_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('plan_id');
        });

        Schema::dropIfExists('plans');
    }
};
