<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cómo se ve el widget en el sitio de la empresa: el modo (claro por defecto, porque casi todos los sitios son
        // claros) y la esquina del botón. Los colores y el logo ya viven en esta misma tabla.
        Schema::table('tenant_branding', function (Blueprint $table): void {
            $table->string('widget_mode', 10)->default('claro')->after('foregrounds');
            $table->string('widget_corner', 5)->default('right')->after('widget_mode');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_branding', function (Blueprint $table): void {
            $table->dropColumn(['widget_mode', 'widget_corner']);
        });
    }
};
