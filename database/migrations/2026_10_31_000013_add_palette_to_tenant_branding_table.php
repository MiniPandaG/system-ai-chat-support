<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table): void {
            // Solo los colores que la empresa cambió respecto a los de fábrica; nulo = paleta por defecto.
            $table->json('colors')->nullable()->after('logo_file_id');
            // El texto calculado sobre cada color, ya resuelto en hexadecimal: el widget y los correos lo leen de aquí.
            $table->json('foregrounds')->nullable()->after('colors');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table): void {
            $table->dropColumn(['colors', 'foregrounds']);
        });
    }
};
