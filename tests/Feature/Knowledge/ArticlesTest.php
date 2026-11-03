<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\AuditEvent;
use App\Enums\Role;
use App\Knowledge\ArticleStatus;
use App\Models\AuditLog;
use App\Models\KbArticle;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\RateLimiter;

describe('crear un artículo', function (): void {
    it('el administrador guarda un borrador y queda con su autor, sin publicar y auditado solo con el identificador', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);

        $response = $this->actingAs($admin)->post('/knowledge/articles', articlePayload());

        $article = app(TenantContext::class)->run($tenant, fn () => KbArticle::query()->firstOrFail());
        $response->assertRedirect("/knowledge/articles/{$article->id}");
        expect($article->status)->toBe(ArticleStatus::Draft)
            ->and($article->tenant_id)->toBe($tenant->id)
            ->and($article->created_by)->toBe($admin->id)
            ->and($article->published_at)->toBeNull()
            ->and($article->category)->toBe('Pagos');
        $log = AuditLog::query()->where('event', AuditEvent::KnowledgeArticleCreated->value)->latest('id')->firstOrFail();
        expect($log->context)->toEqual(['article_id' => $article->id])->and($log->user_id)->toBe($admin->id);
    });

    it('limpia el título y el contenido de invisibles y caracteres de control, y deja el Markdown como texto', function (): void {
        $tenant = ticketTenant();
        $body = "Hola\u{200B} mundo\u{202E} <script>alert(1)</script>\n\n[x](javascript:alert(1))\u{E0041}\u{E0042}\0";

        $this->actingAs(kbAdmin($tenant))->post('/knowledge/articles', articlePayload(['title' => "  Envíos \u{200B}  y   plazos  ", 'body' => $body]))->assertRedirect();

        $article = app(TenantContext::class)->run($tenant, fn () => KbArticle::query()->firstOrFail());
        expect($article->title)->toBe('Envíos y plazos')
            ->and($article->body)->toBe("Hola mundo <script>alert(1)</script>\n\n[x](javascript:alert(1))");
    });

    it('sin categoría la guarda vacía y una categoría de solo espacios también', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);

        $this->actingAs($admin)->post('/knowledge/articles', articlePayload(['category' => '   ']))->assertRedirect();
        $this->actingAs($admin)->post('/knowledge/articles', articlePayload(['title' => 'Otro', 'category' => null]))->assertRedirect();

        $categories = app(TenantContext::class)->run($tenant, fn () => KbArticle::query()->pluck('category')->all());
        expect($categories)->toBe([null, null]);
    });

    it('valida cada campo con un mensaje específico', function (string $field, mixed $value, string $message): void {
        $tenant = ticketTenant();

        $this->actingAs(kbAdmin($tenant))->post('/knowledge/articles', articlePayload([$field => $value]))->assertSessionHasErrors([$field => $message]);

        expect(articleCount($tenant))->toBe(0);
    })->with([
        'sin título' => ['title', '', 'Escribe un título para el artículo.'],
        'título de espacios' => ['title', '   ', 'Escribe un título para el artículo.'],
        'título largo' => ['title', str_repeat('a', 161), 'El título es demasiado largo. El máximo es de 160 caracteres.'],
        'título con control' => ['title', "Hola\x07", 'El título tiene caracteres que no se pueden guardar.'],
        'contenido largo' => ['body', str_repeat('a', 50001), 'El contenido es demasiado largo. El máximo es de 50000 caracteres.'],
        'categoría larga' => ['category', str_repeat('a', 41), 'La categoría es demasiado larga. El máximo es de 40 caracteres.'],
        'categoría con control' => ['category', "Env\x00ios", 'La categoría tiene caracteres que no se pueden guardar.'],
    ]);

    it('acepta un contenido de 50000 caracteres y un título de 160', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(kbAdmin($tenant))->post('/knowledge/articles', articlePayload(['title' => str_repeat('t', 160), 'body' => str_repeat('c', 50000)]))->assertSessionHasNoErrors();

        expect(articleCount($tenant))->toBe(1);
    });

    it('ignora los campos que el cliente no debe escribir', function (): void {
        $tenant = ticketTenant();
        $other = ticketTenant();
        $admin = kbAdmin($tenant);

        $this->actingAs($admin)->post('/knowledge/articles', articlePayload() + ['status' => 'published', 'tenant_id' => $other->id, 'created_by' => 999999, 'published_at' => now()->toDateTimeString()])->assertSessionHasNoErrors();

        $article = app(TenantContext::class)->run($tenant, fn () => KbArticle::query()->firstOrFail());
        expect($article->status)->toBe(ArticleStatus::Draft)
            ->and($article->tenant_id)->toBe($tenant->id)
            ->and($article->created_by)->toBe($admin->id)
            ->and($article->published_at)->toBeNull()
            ->and(articleCount($other))->toBe(0);
    });

    it('no pasa del tope de artículos por empresa y el tope es de cada empresa', function (): void {
        config(['knowledge.articles.max' => 2]);
        $tenant = ticketTenant();
        $other = ticketTenant();
        $admin = kbAdmin($tenant);
        makeArticle($tenant);
        makeArticle($tenant);

        $this->actingAs($admin)->post('/knowledge/articles', articlePayload())->assertSessionHasErrors(['title' => 'Ya tienes 2 artículos, el máximo por empresa. Elimina alguno para crear otro.']);
        $this->actingAs(kbAdmin($other))->post('/knowledge/articles', articlePayload())->assertSessionHasNoErrors();

        expect(articleCount($tenant))->toBe(2)->and(articleCount($other))->toBe(1);
    });

    it('limita las escrituras a 60 por minuto por persona', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        RateLimiter::clear('knowledge-write:'.$admin->id);
        $article = makeArticle($tenant);

        foreach (range(1, 60) as $_) {
            $this->actingAs($admin)->put("/knowledge/articles/{$article->id}", articlePayload())->assertRedirect();
        }

        $this->actingAs($admin)->put("/knowledge/articles/{$article->id}", articlePayload())->assertStatus(429);
    });
});

describe('editar, publicar y borrar', function (): void {
    it('editar cambia los campos, no cambia el estado y no llena la auditoría', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $article = makeArticle($tenant, ['status' => ArticleStatus::Published, 'published_at' => now()]);

        $this->actingAs($admin)->put("/knowledge/articles/{$article->id}", articlePayload(['title' => 'Nuevo título']))->assertRedirect();

        $fresh = articleFresh($tenant, $article->id);
        expect($fresh?->title)->toBe('Nuevo título')
            ->and($fresh?->status)->toBe(ArticleStatus::Published)
            ->and($fresh?->updated_by)->toBe($admin->id)
            ->and(AuditLog::query()->where('event', 'like', 'knowledge.%')->count())->toBe(0);
    });

    it('publicar exige contenido, fija la fecha y audita una sola vez aunque se repita', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        $empty = makeArticle($tenant, ['body' => '   ']);
        $article = makeArticle($tenant);

        $this->actingAs($admin)->post("/knowledge/articles/{$empty->id}/publish")->assertSessionHasErrors(['body' => 'Escribe el contenido del artículo antes de publicarlo.']);
        $this->actingAs($admin)->post("/knowledge/articles/{$article->id}/publish")->assertSessionHasNoErrors();
        $this->actingAs($admin)->post("/knowledge/articles/{$article->id}/publish")->assertSessionHasNoErrors();

        $fresh = articleFresh($tenant, $article->id);
        expect(articleFresh($tenant, $empty->id)?->status)->toBe(ArticleStatus::Draft)
            ->and($fresh?->status)->toBe(ArticleStatus::Published)
            ->and($fresh?->published_at)->not->toBeNull()
            ->and(AuditLog::query()->where('event', AuditEvent::KnowledgeArticlePublished->value)->count())->toBe(1);
    });

    it('volver a borrador quita la fecha de publicación y se audita', function (): void {
        $tenant = ticketTenant();
        $article = makeArticle($tenant, ['status' => ArticleStatus::Published, 'published_at' => now()]);

        $this->actingAs(kbAdmin($tenant))->post("/knowledge/articles/{$article->id}/unpublish")->assertSessionHasNoErrors();

        $fresh = articleFresh($tenant, $article->id);
        expect($fresh?->status)->toBe(ArticleStatus::Draft)->and($fresh?->published_at)->toBeNull()
            ->and(AuditLog::query()->where('event', AuditEvent::KnowledgeArticleUnpublished->value)->count())->toBe(1);
    });

    it('borrar elimina el artículo y deja la auditoría con el identificador', function (): void {
        $tenant = ticketTenant();
        $article = makeArticle($tenant);

        $this->actingAs(kbAdmin($tenant))->delete("/knowledge/articles/{$article->id}")->assertRedirect('/knowledge');

        $log = AuditLog::query()->where('event', AuditEvent::KnowledgeArticleDeleted->value)->firstOrFail();
        expect(articleFresh($tenant, $article->id))->toBeNull()
            ->and($log->context)->toEqual(['article_id' => $article->id])
            ->and(json_encode($log->context))->not->toContain('dirección');
    });
});

describe('quién puede qué', function (): void {
    it('solo el administrador escribe; el agente lee; el observador, la plataforma y los desactivados no entran', function (): void {
        $tenant = ticketTenant();
        $article = makeArticle($tenant);
        $users = [
            'admin' => [kbAdmin($tenant), true, true],
            'agent' => [ticketStaff($tenant, Role::Agent), true, false],
            'observer' => [ticketStaff($tenant, Role::Observer), false, false],
        ];

        foreach ($users as $label => [$user, $reads, $writes]) {
            $this->actingAs($user);
            $this->get('/knowledge')->assertStatus($reads ? 200 : 403);
            $this->get("/knowledge/articles/{$article->id}")->assertStatus($reads ? 200 : 403);
            $this->get('/knowledge/articles/create')->assertStatus($writes ? 200 : 403);
            $this->post('/knowledge/articles', articlePayload())->assertStatus($writes ? 302 : 403);
            $this->put("/knowledge/articles/{$article->id}", articlePayload())->assertStatus($writes ? 302 : 403);
            $this->post("/knowledge/articles/{$article->id}/publish")->assertStatus($writes ? 302 : 403);
            $this->post("/knowledge/articles/{$article->id}/unpublish")->assertStatus($writes ? 302 : 403);
            $this->delete("/knowledge/articles/{$article->id}")->assertStatus($writes ? 302 : 403);
            $article = $writes ? makeArticle($tenant) : $article;
            expect($label)->toBeString();
        }
    });

    it('un administrador desactivado no entra', function (): void {
        $tenant = ticketTenant();

        $this->actingAs(ticketStaff($tenant, Role::Admin, disabled: true))->get('/knowledge')->assertRedirect();
    });

    it('el personal de la plataforma no entra', function (): void {
        $platform = User::factory()->create(['is_platform_admin' => true, 'tenant_id' => null, 'role' => null]);
        $platform->forceFill(['two_factor_secret' => encrypt('S'), 'two_factor_recovery_codes' => encrypt('[]'), 'two_factor_confirmed_at' => now()])->save();

        $this->actingAs($platform)->get('/knowledge')->assertForbidden();
    });

    it('a un agente la pantalla le llega de solo lectura y a un administrador editable', function (): void {
        $tenant = ticketTenant();
        $article = makeArticle($tenant);

        $agent = $this->actingAs(ticketStaff($tenant, Role::Agent))->get("/knowledge/articles/{$article->id}")->viewData('page')['props'];
        $admin = $this->actingAs(kbAdmin($tenant))->get("/knowledge/articles/{$article->id}")->viewData('page')['props'];

        expect($agent['can_edit'])->toBeFalse()->and($admin['can_edit'])->toBeTrue();
    });

    it('la entrada del panel solo la ven quienes pueden leer', function (): void {
        $tenant = ticketTenant();

        foreach ([[Role::Admin, true], [Role::Agent, true], [Role::Observer, false]] as [$role, $sees]) {
            $can = $this->actingAs(ticketStaff($tenant, $role))->get('/account')->viewData('page')['props']['auth']['can'];
            expect($can['knowledge'])->toBe($sees);
        }
    });

    it('sin sesión pide iniciar sesión', function (): void {
        $this->get('/knowledge')->assertRedirect('/login');
        $this->post('/knowledge/articles', articlePayload())->assertRedirect('/login');
    });
});

describe('aislamiento entre empresas', function (): void {
    it('un artículo de otra empresa da 404 por la interfaz', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        $foreign = makeArticle($theirs, ['title' => 'Secreto de la otra empresa']);
        $admin = kbAdmin($mine);

        $this->actingAs($admin);
        $this->get("/knowledge/articles/{$foreign->id}")->assertNotFound();
        $this->put("/knowledge/articles/{$foreign->id}", articlePayload())->assertNotFound();
        $this->post("/knowledge/articles/{$foreign->id}/publish")->assertNotFound();
        $this->post("/knowledge/articles/{$foreign->id}/unpublish")->assertNotFound();
        $this->delete("/knowledge/articles/{$foreign->id}")->assertNotFound();

        expect(articleFresh($theirs, $foreign->id)?->title)->toBe('Secreto de la otra empresa')
            ->and(AuditLog::query()->where('event', 'like', 'knowledge.%')->count())->toBe(0);
    });

    it('la lista y las categorías solo traen lo de la empresa', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        makeArticle($mine, ['title' => 'Mío', 'category' => 'Envíos']);
        makeArticle($theirs, ['title' => 'Ajeno', 'category' => 'Contabilidad']);

        $props = $this->actingAs(kbAdmin($mine))->get('/knowledge')->viewData('page')['props'];

        expect(array_column($props['articles'], 'title'))->toBe(['Mío'])
            ->and($props['categories'])->toBe(['Envíos'])
            ->and($props['total'])->toBe(1);
    });

    it('filtrar por la categoría de otra empresa no devuelve nada', function (): void {
        $mine = ticketTenant();
        $theirs = ticketTenant();
        makeArticle($mine);
        makeArticle($theirs, ['category' => 'Contabilidad']);

        $props = $this->actingAs(kbAdmin($mine))->get('/knowledge?category=Contabilidad')->viewData('page')['props'];

        expect($props['articles'])->toBe([]);
    });
});

describe('la lista', function (): void {
    it('filtra por categoría, estado y título, y escapa los comodines', function (): void {
        $tenant = ticketTenant();
        $admin = kbAdmin($tenant);
        makeArticle($tenant, ['title' => 'Devoluciones y reembolsos', 'category' => 'Pagos', 'status' => ArticleStatus::Published, 'published_at' => now()]);
        makeArticle($tenant, ['title' => 'Cambio de dirección', 'category' => 'Envíos']);
        makeArticle($tenant, ['title' => 'Descuento del 50% en todo', 'category' => 'Pagos']);

        $titles = fn (string $query): array => array_column($this->actingAs($admin)->get("/knowledge{$query}")->viewData('page')['props']['articles'], 'title');

        expect($titles('?category=Pagos'))->toEqualCanonicalizing(['Devoluciones y reembolsos', 'Descuento del 50% en todo'])
            ->and($titles('?status=published'))->toBe(['Devoluciones y reembolsos'])
            ->and($titles('?status=draft'))->toHaveCount(2)
            ->and($titles('?q=dirección'))->toBe(['Cambio de dirección'])
            ->and($titles('?q=50%25'))->toBe(['Descuento del 50% en todo'])
            ->and($titles('?q=%25'))->toBe(['Descuento del 50% en todo'])
            ->and($titles('?q=_'))->toBe([])
            ->and($titles('?status=cualquiera'))->toHaveCount(3);
    });

    it('pagina de 25 en 25, de lo más reciente a lo más antiguo', function (): void {
        $tenant = ticketTenant();
        foreach (range(1, 30) as $i) {
            makeArticle($tenant, ['title' => "Artículo {$i}", 'updated_at' => now()->subMinutes(100 - $i)]);
        }

        $first = $this->actingAs(kbAdmin($tenant))->get('/knowledge')->viewData('page')['props'];
        $second = $this->actingAs(kbAdmin($tenant))->get('/knowledge?page=2')->viewData('page')['props'];

        expect($first['articles'])->toHaveCount(25)
            ->and($first['articles'][0]['title'])->toBe('Artículo 30')
            ->and($first['pagination'])->toMatchArray(['current' => 1, 'last' => 2, 'total' => 30])
            ->and($second['articles'])->toHaveCount(5)
            ->and($second['pagination']['previous'])->not->toBeNull();
    });

    it('el título buscado se acorta y los valores que no son texto se descartan', function (): void {
        $tenant = ticketTenant();
        makeArticle($tenant);

        $this->actingAs(kbAdmin($tenant))->get('/knowledge?q[]=a&category[]=b&status[]=draft')->assertOk();
        $this->actingAs(kbAdmin($tenant))->get('/knowledge?q='.str_repeat('a', 5000))->assertOk();
    });
});

describe('texto de las pantallas', function (): void {
    it('un título con HTML llega tal cual como texto, sin interpretarse', function (): void {
        $tenant = ticketTenant();
        $article = makeArticle($tenant, ['title' => '<img src=x onerror=alert(1)>']);

        $props = $this->actingAs(kbAdmin($tenant))->get("/knowledge/articles/{$article->id}")->viewData('page')['props'];

        expect($props['article']['title'])->toBe('<img src=x onerror=alert(1)>');
    });

    it('la pantalla trae los límites y los textos', function (): void {
        $props = $this->actingAs(kbAdmin(ticketTenant()))->get('/knowledge/articles/create')->viewData('page')['props'];

        expect($props['limits'])->toBe(['title' => 160, 'body' => 50000, 'category' => 40])
            ->and($props['article'])->toBeNull()
            ->and($props['copy']['new_title'])->toBe('Nuevo artículo');
    });
});
