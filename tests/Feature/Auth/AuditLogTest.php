<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function newAuditEntry(): AuditLog
{
    return app(AuditLogger::class)->record(AuditEvent::LoginSucceeded, 1);
}

it('el modelo no permite modificar ni borrar registros', function (): void {
    $entry = newAuditEntry();

    $entry->event = 'otro.evento';
    expect(fn () => $entry->save())->toThrow(LogicException::class)
        ->and(fn () => $entry->delete())->toThrow(LogicException::class);
});

it('la base de datos rechaza UPDATE y DELETE sobre audit_logs, también con consultas directas', function (): void {
    $entry = newAuditEntry();

    expect(fn () => DB::table('audit_logs')->where('id', $entry->id)->update(['event' => 'manipulado']))
        ->toThrow(QueryException::class, 'solo insercion')
        ->and(fn () => DB::table('audit_logs')->where('id', $entry->id)->delete())
        ->toThrow(QueryException::class, 'solo insercion');

    expect(AuditLog::find($entry->id)?->event)->toBe(AuditEvent::LoginSucceeded->value);
});
