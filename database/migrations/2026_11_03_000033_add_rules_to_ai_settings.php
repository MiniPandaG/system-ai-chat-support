<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table): void {
            // El modelo que resume el caso al escalar; nulo = el mismo que redacta las respuestas.
            $table->string('summary_model', 40)->nullable()->after('reply_model');
            // Por debajo de este porcentaje la IA no responde y pasa el caso a una persona.
            $table->unsignedTinyInteger('min_confidence')->default(70)->after('require_knowledge');
            $table->boolean('show_sources')->default(true)->after('min_confidence');
            $table->string('tone', 10)->default('friendly')->after('show_sources');
            $table->text('tone_custom')->nullable()->after('tone');
            // «always», «within» (solo dentro del horario) u «outside» (solo fuera de él).
            $table->string('schedule_mode', 10)->default('always')->after('tone_custom');
            $table->json('schedule_days')->nullable()->after('schedule_mode');
            $table->string('schedule_start', 5)->default('09:00')->after('schedule_days');
            $table->string('schedule_end', 5)->default('18:00')->after('schedule_start');
            $table->string('schedule_timezone', 40)->default('America/Bogota')->after('schedule_end');
            $table->json('escalate_topics')->nullable()->after('schedule_timezone');
            $table->unsignedTinyInteger('max_messages')->default(5)->after('escalate_topics');
            // Topes de gasto en millonésimas de dólar; nulo = el valor de la plataforma.
            $table->unsignedBigInteger('daily_spend_micro')->nullable()->after('max_messages');
            $table->unsignedBigInteger('monthly_spend_micro')->nullable()->after('daily_spend_micro');
        });

        // Una nota sin autor es del asistente de IA: el resumen con el que pasa un caso a una persona.
        Schema::table('ticket_notes', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
        });

        Schema::table('ai_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'summary_model', 'min_confidence', 'show_sources', 'tone', 'tone_custom', 'schedule_mode', 'schedule_days',
                'schedule_start', 'schedule_end', 'schedule_timezone', 'escalate_topics', 'max_messages', 'daily_spend_micro', 'monthly_spend_micro',
            ]);
        });
    }
};
