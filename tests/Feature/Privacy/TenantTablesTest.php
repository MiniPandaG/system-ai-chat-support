<?php

declare(strict_types=1);

use App\Privacy\TenantTables;
use Illuminate\Support\Facades\Schema;

/** @return list<string> */
function tablesWithTenantId(): array
{
    return collect(Schema::getTableListing(schemaQualified: false))
        ->unique()
        ->filter(fn (string $table): bool => Schema::hasColumn($table, 'tenant_id'))
        ->sort()
        ->values()
        ->all();
}

it('el borrado conoce todas las tablas con datos de empresa: una tabla nueva sin registrar rompe esta prueba', function (): void {
    $known = [...TenantTables::PURGE_ORDER, 'users'];
    sort($known);

    expect(tablesWithTenantId())->toBe($known);
});

it('el orden de borrado no repite tablas y respeta las claves foráneas que no se pueden saltar', function (): void {
    expect(TenantTables::PURGE_ORDER)->toBe(array_values(array_unique(TenantTables::PURGE_ORDER)));

    $position = array_flip(TenantTables::PURGE_ORDER);
    $violations = [];

    foreach (TenantTables::PURGE_ORDER as $table) {
        foreach (Schema::getForeignKeys($table) as $foreign) {
            $target = $foreign['foreign_table'];

            if (! isset($position[$target]) || in_array($foreign['on_delete'], ['cascade', 'set null'], true)) {
                continue;
            }

            if ($position[$table] > $position[$target]) {
                $violations[] = "{$table} apunta a {$target}, pero se borra después";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('cada tabla con datos de empresa se exporta o dice por qué no', function (): void {
    $covered = [...array_keys(TenantTables::EXPORT), ...array_keys(TenantTables::NOT_EXPORTED)];
    $all = [...TenantTables::PURGE_ORDER, 'users'];
    sort($covered);
    sort($all);

    expect($covered)->toBe($all)
        ->and(array_intersect(array_keys(TenantTables::EXPORT), array_keys(TenantTables::NOT_EXPORTED)))->toBe([]);

    foreach (TenantTables::NOT_EXPORTED as $reason) {
        expect($reason)->not->toBe('');
    }
});

it('la exportación no deja pasar ninguna columna que parezca un secreto', function (): void {
    $leaks = [];

    foreach (TenantTables::EXPORT as $table => $except) {
        $columns = Schema::getColumnListing($table);

        foreach ($except as $column) {
            expect($columns)->toContain($column);
        }

        foreach (array_diff($columns, $except) as $column) {
            if (preg_match(TenantTables::SECRET_COLUMN, $column) === 1) {
                $leaks[] = "{$table}.{$column}";
            }
        }
    }

    expect($leaks)->toBe([]);
});
