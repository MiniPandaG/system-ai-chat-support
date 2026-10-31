<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modelo de prueba con datos de empresa, sobre una tabla temporal de la conexión de migraciones (la de la
 * aplicación no tiene DDL). Sirve mientras no exista un modelo de empresa real.
 */
#[Fillable(['label'])]
class TenantProbe extends Model
{
    use BelongsToTenant;

    public $timestamps = false;

    protected $connection = 'mysql_migrator';

    protected $table = 'tenant_probes';

    /**
     * Una tabla temporal vive solo en su conexión y no provoca confirmación implícita de la transacción de la prueba.
     */
    public static function createTable(): void
    {
        Schema::connection('mysql_migrator')->dropIfExists('tenant_probes');
        Schema::connection('mysql_migrator')->create('tenant_probes', function (Blueprint $table): void {
            $table->temporary();
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('label');
        });
    }
}
