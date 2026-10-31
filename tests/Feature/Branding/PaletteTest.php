<?php

declare(strict_types=1);

use App\Branding\BrandColor;
use App\Branding\Palette;

it('los colores de fábrica de la configuración coinciden con los de tokens.css', function (): void {
    $css = (string) file_get_contents(base_path('resources/tokens/tokens.css'));

    foreach (BrandColor::cases() as $color) {
        expect($css)->toMatch('/--color-'.$color->value.':\s*'.preg_quote($color->defaultHex(), '/').'\s*;/i', "El token {$color->value} difiere de config/branding.php");
    }
});

it('solo acepta #RRGGBB exacto y lo pasa a mayúsculas', function (mixed $value, ?string $expected): void {
    expect(Palette::normalize($value))->toBe($expected);
})->with([
    'mayúsculas' => ['#F65F1E', '#F65F1E'],
    'minúsculas' => ['#f65f1e', '#F65F1E'],
    'mezcla' => ['#aBcDeF', '#ABCDEF'],
    'tres dígitos' => ['#FFF', null],
    'sin almohadilla' => ['F65F1E', null],
    'siete dígitos' => ['#F65F1E0', null],
    'con alfa' => ['#F65F1E80', null],
    'nombre de color' => ['red', null],
    'rgb()' => ['rgb(255,0,0)', null],
    'salto de línea final' => ["#F65F1E\n", null],
    'con espacios' => [' #F65F1E', null],
    'inyección de declaración' => ['#F65F1E;--x:1', null],
    'inyección de bloque' => ['red;}body{display:none', null],
    'url()' => ['url(https://x.test/a.png)', null],
    'hexadecimal inválido' => ['#GGGGGG', null],
    'vacío' => ['', null],
    'número' => [123456, null],
    'lista' => [['#F65F1E'], null],
    'nulo' => [null, null],
]);

it('calcula el contraste WCAG con valores conocidos', function (): void {
    expect(Palette::contrast('#000000', '#FFFFFF'))->toEqualWithDelta(21.0, 0.0001)
        ->and(Palette::contrast('#FFFFFF', '#000000'))->toEqualWithDelta(21.0, 0.0001)
        ->and(Palette::contrast('#777777', '#777777'))->toBe(1.0)
        ->and(Palette::contrast('#F65F1E', '#1D1208'))->toEqualWithDelta(5.755, 0.001);
});

it('elige el texto claro u oscuro del sistema, el de más contraste, y nunca blanco ni negro puros', function (string $hex, string $expected): void {
    $foreground = Palette::foreground($hex);

    expect($foreground['hex'])->toBe($expected)
        ->and($foreground['hex'])->not->toBe('#FFFFFF')->and($foreground['hex'])->not->toBe('#000000');
})->with([
    'naranja de fábrica' => ['#F65F1E', '#1D1208'],
    'azul tinta de fábrica' => ['#2E5EAA', '#F6F1EA'],
    'amarillo' => ['#F5D23E', '#1D1208'],
    'negro' => ['#000000', '#F6F1EA'],
    'blanco' => ['#FFFFFF', '#1D1208'],
]);

it('la paleta de fábrica pasa la revisión y no genera problemas', function (): void {
    $report = Palette::check(config()->array('branding.defaults'));

    expect($report->passes())->toBeTrue()
        ->and($report->colors)->toHaveCount(10)
        ->and($report->foregrounds)->toHaveCount(7)
        ->and($report->foregrounds['primary'])->toBe('#1D1208');
});

it('los cuatro colores de prueba del diseño dan el resultado del cálculo real', function (): void {
    // Los valores de design/branding-por-empresa.md §4 no coinciden con la fórmula; estos son los medidos.
    $azul = Palette::check(['primary' => '#2F6FE0']);
    expect($azul->passes())->toBeFalse()
        ->and($azul->issues[0]->kind)->toBe('foreground')
        ->and($azul->issues[0]->message)->toContain('4,1:1')->toContain('mínimo 4,5:1')
        ->and($azul->issues[0]->suggestionHex)->not->toBeNull();

    expect(Palette::check(['primary' => '#1F8F5C'])->passes())->toBeTrue()
        ->and(Palette::check(['primary' => '#F5D23E'])->passes())->toBeTrue()
        ->and(Palette::check(['primary' => '#F65F1E'])->passes())->toBeTrue();
});

it('la sugerencia de un color rechazado siempre cumple la regla que se incumplía', function (): void {
    $checked = 0;

    for ($i = 0; $i < 400; $i++) {
        $hex = sprintf('#%06X', random_int(0, 0xFFFFFF));

        foreach (['primary', 'secondary', 'accent', 'success', 'warning', 'danger', 'info'] as $key) {
            $issue = collect(Palette::check([$key => $hex])->issues)->firstWhere('kind', 'foreground');

            if ($issue === null || $issue->suggestionHex === null) {
                continue;
            }

            $retry = collect(Palette::check([$key => $issue->suggestionHex])->issues)->where('kind', 'foreground');

            expect($retry)->toHaveCount(0, "{$key} {$hex} → {$issue->suggestionHex}");
            $checked++;
        }
    }

    expect($checked)->toBeGreaterThan(20);
});

it('rechaza un texto que no se lee sobre el fondo y propone un tono que sí', function (): void {
    $report = Palette::check(['text' => '#16120E']);
    $issue = collect($report->issues)->firstWhere('kind', 'text_background');

    expect($issue?->key)->toBe('text')
        ->and($issue?->message)->toContain('fondo o la superficie')
        ->and($issue?->suggestionHex)->not->toBeNull();

    $fixed = Palette::check(['text' => (string) $issue?->suggestionHex]);

    expect(collect($fixed->issues)->where('kind', 'text_background'))->toHaveCount(0);
});

it('un fondo claro con el texto claro de fábrica se rechaza y se corrige en el fondo', function (): void {
    $issue = collect(Palette::check(['background' => '#FFFFFF'])->issues)->firstWhere('kind', 'text_background');

    expect($issue?->key)->toBe('background')->and($issue?->suggestionHex)->not->toBeNull();

    $light = collect(Palette::check(['background' => '#FFFFFF', 'text' => '#221B14', 'surface' => '#F7F4EF'])->issues)->where('kind', 'text_background');

    expect($light)->toHaveCount(0);
});

it('un primario que casi no se ve sobre el fondo se rechaza con su razón de contraste', function (): void {
    $issue = collect(Palette::check(['primary' => '#1D1813'])->issues)->firstWhere('kind', 'primary_background');

    expect($issue?->key)->toBe('primary')
        ->and($issue?->message)->toContain('mínimo 3,0:1')
        ->and($issue?->suggestionHex)->not->toBeNull();

    $fixed = Palette::check(['primary' => (string) $issue?->suggestionHex]);

    expect($fixed->passes())->toBeTrue();
});

it('el primario también debe verse sobre el fondo oscuro del panel aunque la empresa tenga fondo claro', function (): void {
    $report = Palette::check(['background' => '#FFFFFF', 'surface' => '#F7F4EF', 'text' => '#221B14', 'primary' => '#2B2B2B']);

    expect(collect($report->issues)->pluck('kind')->all())->toContain('primary_background');
});

it('los formatos inválidos se reportan por color y no rompen la revisión', function (): void {
    $report = Palette::check(['primary' => 'red', 'accent' => "#2BAFBF\n", 'secondary' => '#2E5EAA']);

    expect(collect($report->issues)->where('kind', 'format')->pluck('key')->sort()->values()->all())->toBe(['accent', 'primary'])
        ->and($report->colors['primary'])->toBe('#F65F1E');
});

it('las claves desconocidas se ignoran', function (): void {
    $report = Palette::check(['--evil' => '#000000', 'font-size' => '99px', 'primary' => '#F65F1E']);

    expect($report->passes())->toBeTrue()->and($report->colors)->toHaveCount(10);
});

it('para guardar solo se conservan los colores que difieren de fábrica, con todos los textos calculados', function (): void {
    $stored = Palette::forStorage(['primary' => '#2164DA', 'secondary' => '#2E5EAA', 'accent' => '#2bafbf']);

    expect($stored['colors'])->toBe(['primary' => '#2164DA'])
        ->and($stored['foregrounds'])->toHaveCount(7)
        ->and($stored['foregrounds']['primary'])->toBe('#F6F1EA');

    expect(Palette::forStorage(config()->array('branding.defaults'))['colors'])->toBeNull();
});

it('las variables del panel son solo el primario y su texto', function (): void {
    expect(Palette::panelVariables(['primary' => '#2164DA', 'accent' => '#FF0000'], ['primary' => '#F6F1EA', 'accent' => '#000000']))
        ->toBe(['--color-primary' => '#2164DA', '--color-primary-foreground' => '#F6F1EA'])
        ->and(Palette::panelVariables(null, null))->toBe([])
        ->and(Palette::panelVariables(['primary' => 'red'], ['primary' => '#F6F1EA']))->toBe([])
        ->and(Palette::panelVariables(['primary' => '#2164DA'], null))->toBe([]);
});

it('el CSS solo contiene nombres de la lista cerrada y valores #RRGGBB: nada más puede llegar', function (): void {
    $css = Palette::css([
        '--color-primary' => '#2164DA',
        '--color-primary-foreground' => '#f6f1ea',
        '--color-evil' => '#000000',
        'background' => '#111111',
        '--color-accent' => 'red;}body{display:none',
        '--color-text' => "#FFFFFF\n}",
        '--color-surface' => 'url(https://x.test/a.png)',
        'x:expression(alert(1))' => '#ABCDEF',
    ]);

    expect($css)->toBe(':root{--color-primary:#2164DA;--color-primary-foreground:#F6F1EA;}')
        ->and(Palette::css([]))->toBe('')
        ->and(Palette::css(['--color-primary' => 'nope']))->toBe('');
});
