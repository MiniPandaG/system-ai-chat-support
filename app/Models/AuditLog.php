<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Registro de solo inserción: la base de datos rechaza UPDATE y DELETE, y aquí tampoco se permiten.
 */
#[Fillable(['event', 'user_id', 'ip_address', 'user_agent', 'context'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  array{touch?: bool|null}  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('Los registros de auditoría no se pueden modificar.');
        }

        return parent::save($options);
    }

    public function delete(): never
    {
        throw new LogicException('Los registros de auditoría no se pueden borrar.');
    }
}
