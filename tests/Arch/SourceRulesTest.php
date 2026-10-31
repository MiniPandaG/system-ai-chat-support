<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * Lista explícita y justificada de archivos que pueden usar SQL crudo.
 * Cada entrada debe llevar un motivo y haber sido revisada por una persona.
 *
 * @var list<string>
 */
const RAW_SQL_ALLOWLIST = [];

/**
 * @param  list<string>  $directories
 * @param  list<string>  $extensions
 * @return array<string, string>
 */
function sourceFiles(array $directories, array $extensions): array
{
    $base = dirname(__DIR__, 2);
    $existing = array_values(array_filter(
        array_map(static fn (string $dir): string => $base.'/'.$dir, $directories),
        is_dir(...),
    ));

    if ($existing === []) {
        return [];
    }

    $finder = Finder::create()->files()->in($existing);
    foreach ($extensions as $extension) {
        $finder->name('*.'.$extension);
    }

    $files = [];
    foreach ($finder as $file) {
        $files[substr($file->getPathname(), strlen($base) + 1)] = (string) file_get_contents($file->getPathname());
    }

    return $files;
}

/**
 * @param  array<string, string>  $files
 * @return list<string>
 */
function filesMatching(array $files, string $pattern, array $except = []): array
{
    $hits = [];
    foreach ($files as $path => $contents) {
        if (in_array(str_replace('\\', '/', $path), $except, true)) {
            continue;
        }
        if (preg_match($pattern, $contents) === 1) {
            $hits[] = $path;
        }
    }

    return $hits;
}

it('no llama a env() fuera de los archivos de configuración', function (): void {
    $files = sourceFiles(['app', 'bootstrap', 'routes', 'database', 'resources/views', 'lang'], ['php']);

    expect(filesMatching($files, '/\benv\s*\(/'))->toBe([]);
});

it('no declara $guarded vacío en ningún modelo', function (): void {
    $files = sourceFiles(['app'], ['php']);

    expect(filesMatching($files, '/\$guarded\s*=\s*\[\s*\]/'))->toBe([]);
});

it('no usa SQL crudo fuera de la lista explícita', function (): void {
    $files = sourceFiles(['app', 'database', 'routes'], ['php']);
    $pattern = '/(DB::raw|->(whereRaw|selectRaw|orderByRaw|havingRaw|groupByRaw|joinRaw)\s*\()/';

    expect(filesMatching($files, $pattern, RAW_SQL_ALLOWLIST))->toBe([]);
});

it('no vuelca la petición completa hacia create o update', function (): void {
    $files = sourceFiles(['app'], ['php']);

    expect(filesMatching($files, '/->(create|update|fill|forceFill|firstOrCreate|updateOrCreate)\s*\(\s*\$request->all\s*\(\s*\)/'))->toBe([]);
});

it('no desactiva los filtros globales sin revisión', function (): void {
    $files = sourceFiles(['app', 'routes', 'database'], ['php']);

    expect(filesMatching($files, '/withoutGlobalScope/'))->toBe([]);
});

it('no silencia errores con @ ni deja bloques catch vacíos', function (): void {
    $files = sourceFiles(['app', 'routes', 'database'], ['php']);

    expect(filesMatching($files, '/catch\s*\([^)]*\)\s*\{\s*\}/'))->toBe([]);
    expect(filesMatching($files, '/(?<![\w\'"])@\s*(?:file_get_contents|fopen|unlink|include|require|mkdir|unserialize|\$)/'))->toBe([]);
});

it('no imprime HTML sin escapar en las vistas Blade', function (): void {
    $files = sourceFiles(['resources/views'], ['php']);

    expect(filesMatching($files, '/\{!!/'))->toBe([]);
});

it('no usa dangerouslySetInnerHTML ni eval en el código de React', function (): void {
    $files = sourceFiles(['resources/js'], ['ts', 'tsx']);

    expect(filesMatching($files, '/dangerouslySetInnerHTML|\beval\s*\(|new\s+Function\s*\(/'))->toBe([]);
});

it('no deja console.log ni debugger en el código de React', function (): void {
    $files = sourceFiles(['resources/js'], ['ts', 'tsx']);

    expect(filesMatching($files, '/console\.(log|debug)\s*\(|\bdebugger\b/'))->toBe([]);
});

it('no deja TODO ni FIXME relacionados con seguridad', function (): void {
    $files = sourceFiles(['app', 'routes', 'config', 'database', 'resources/js', 'resources/views'], ['php', 'ts', 'tsx']);

    expect(filesMatching($files, '/(TODO|FIXME)[^\n]*(segur|secret|token|auth|permis|password|contrase)/i'))->toBe([]);
});
