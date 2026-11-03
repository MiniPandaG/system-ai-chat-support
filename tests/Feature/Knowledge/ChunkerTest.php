<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Knowledge\ArticleStatus;
use App\Knowledge\Chunker;

describe('Chunker', function (): void {
    it('un texto corto queda en un solo fragmento', function (): void {
        expect(Chunker::split("Hola mundo.\n\nSegundo párrafo.", 800, 100))->toBe(["Hola mundo.\n\nSegundo párrafo."]);
    });

    it('un texto vacío o de espacios no da fragmentos', function (): void {
        expect(Chunker::split('', 800, 100))->toBe([])->and(Chunker::split("  \n\n  ", 800, 100))->toBe([]);
    });

    it('junta párrafos mientras caben y abre otro fragmento cuando ya no', function (): void {
        $paragraphs = array_map(fn (int $i): string => "Párrafo {$i} ".str_repeat('x', 30), range(1, 6));

        $chunks = Chunker::split(implode("\n\n", $paragraphs), 100, 0);

        expect($chunks)->toHaveCount(3)->and($chunks[0])->toBe($paragraphs[0]."\n\n".$paragraphs[1]);
    });

    it('ningún fragmento pasa del tamaño máximo, con párrafos largos, palabras largas y acentos', function (): void {
        $long = str_repeat('Información útil sobre envíos y devoluciones. ', 60);
        $word = str_repeat('ñ', 400);
        $text = "Corto.\n\n{$long}\n\n{$word}\n\nFin.";

        $chunks = Chunker::split($text, 120, 30);

        expect($chunks)->not->toBe([]);
        foreach ($chunks as $chunk) {
            expect(mb_strlen($chunk))->toBeLessThanOrEqual(120)->and(trim($chunk))->not->toBe('');
        }
    });

    it('no pierde ninguna palabra del texto original', function (): void {
        $text = collect(range(1, 80))->map(fn (int $i): string => "palabra{$i}")->implode(' ')."\n\n".collect(range(81, 120))->map(fn (int $i): string => "otra{$i}")->implode(' ');

        $joined = implode(' ', Chunker::split($text, 90, 20));

        foreach (range(1, 80) as $i) {
            expect($joined)->toContain("palabra{$i}");
        }
        foreach (range(81, 120) as $i) {
            expect($joined)->toContain("otra{$i}");
        }
    });

    it('el inicio de cada fragmento repite el final del anterior', function (): void {
        $text = implode("\n\n", array_map(fn (int $i): string => "bloque{$i} ".str_repeat('texto ', 10), range(1, 5)));

        $chunks = Chunker::split($text, 100, 30);

        expect(count($chunks))->toBeGreaterThan(2);
        $firstWords = explode(' ', trim($chunks[1]));
        expect($chunks[0])->toContain($firstWords[0]);
    });

    it('con superposición cero los fragmentos no se repiten', function (): void {
        $text = implode("\n\n", array_map(fn (int $i): string => "bloque{$i} ".trim(str_repeat('texto ', 10)), range(1, 4)));

        $chunks = Chunker::split($text, 80, 0);

        expect(implode("\n\n", $chunks))->toBe($text);
    });
});

describe('fragmentos de artículos', function (): void {
    it('publicar un artículo crea sus fragmentos; un borrador no tiene ninguno', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $draft = makeArticle($tenant);
        $published = makeArticle($tenant, ['title' => 'Otro', 'body' => 'Texto para citar.']);

        $this->actingAs($admin)->post("/knowledge/articles/{$published->id}/publish")->assertSessionHasNoErrors();

        expect(chunksOf($tenant, $draft))->toBe([])->and(chunksOf($tenant, $published))->toBe(['Texto para citar.']);
    });

    it('editar un artículo publicado rehace sus fragmentos; editar un borrador no crea ninguno', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $published = makeArticle($tenant, ['body' => 'Texto viejo.']);
        $draft = makeArticle($tenant, ['title' => 'Borrador', 'body' => 'Borrador viejo.']);
        $this->actingAs($admin)->post("/knowledge/articles/{$published->id}/publish");

        $this->actingAs($admin)->put("/knowledge/articles/{$published->id}", articlePayload(['body' => 'Texto nuevo.']));
        $this->actingAs($admin)->put("/knowledge/articles/{$draft->id}", articlePayload(['body' => 'Borrador nuevo.']));

        expect(chunksOf($tenant, $published))->toBe(['Texto nuevo.'])->and(chunksOf($tenant, $draft))->toBe([]);
    });

    it('volver a borrador y eliminar borran los fragmentos', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $one = makeArticle($tenant, ['body' => 'Uno.']);
        $two = makeArticle($tenant, ['title' => 'Dos', 'body' => 'Dos.']);
        $this->actingAs($admin)->post("/knowledge/articles/{$one->id}/publish");
        $this->actingAs($admin)->post("/knowledge/articles/{$two->id}/publish");
        expect(chunkTotal($tenant))->toBe(2);

        $this->actingAs($admin)->post("/knowledge/articles/{$one->id}/unpublish");
        $this->actingAs($admin)->delete("/knowledge/articles/{$two->id}");

        expect(chunkTotal($tenant))->toBe(0);
    });

    it('los fragmentos de una empresa no se tocan al publicar en otra', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $theirArticle = makeArticle($theirs, ['body' => 'Ajeno.']);
        $mineArticle = makeArticle($mine, ['body' => 'Mío.']);
        $this->actingAs(kbAdmin($theirs))->post("/knowledge/articles/{$theirArticle->id}/publish");

        $this->actingAs(kbAdmin($mine))->post("/knowledge/articles/{$mineArticle->id}/publish");
        $this->actingAs(kbAdmin($mine))->delete("/knowledge/articles/{$mineArticle->id}");

        expect(chunksOf($theirs, $theirArticle))->toBe(['Ajeno.'])->and(chunkTotal($theirs))->toBe(1);
    });

    it('si los fragmentos pasarían el tope no se publica y el artículo sigue en borrador', function (): void {
        config(['knowledge.chunks.max_per_tenant' => 1]);
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $first = makeArticle($tenant, ['body' => 'Uno.']);
        $second = makeArticle($tenant, ['title' => 'Dos', 'body' => 'Dos.']);
        $this->actingAs($admin)->post("/knowledge/articles/{$first->id}/publish")->assertSessionHasNoErrors();

        $this->actingAs($admin)->post("/knowledge/articles/{$second->id}/publish")->assertSessionHasErrors('body');

        expect(articleFresh($tenant, $second->id)?->status)->toBe(ArticleStatus::Draft)->and(chunkTotal($tenant))->toBe(1);
    });
});
