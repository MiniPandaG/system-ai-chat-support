<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Enums\Role;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use App\Tickets\TagTone;
use App\Tickets\TicketStatus;
use Inertia\Testing\AssertableInertia;

/**
 * @return list<string>
 */
function tagNamesOn(Ticket $ticket): array
{
    return app(TenantContext::class)->run($ticket->tenant()->firstOrFail(), fn () => $ticket->tags()->orderBy('name')->pluck('name')->all());
}

it('crea una etiqueta en el momento y la pone al ticket', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/tags", ['name' => 'Facturación', 'tone' => 'warning'])->assertRedirect()->assertSessionHasNoErrors();

    expect(tagNamesOn($ticket))->toBe(['Facturación'])
        ->and(tagsOf($tenant))->toHaveCount(1)
        ->and(tagsOf($tenant)->first())->tone->toBe(TagTone::Warning);
});

it('sin tono elige el neutro', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/tags", ['name' => 'VIP'])->assertRedirect();

    expect(tagsOf($tenant)->first())->tone->toBe(TagTone::Neutral);
});

it('pone una etiqueta que ya existe en la empresa', function (): void {
    $tenant = ticketTenant();
    $tag = makeTag($tenant, ['name' => 'Urgente cliente']);
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/tags", ['tag_id' => $tag->id])->assertRedirect()->assertSessionHasNoErrors();

    expect(tagNamesOn($ticket))->toBe(['Urgente cliente'])->and(tagsOf($tenant))->toHaveCount(1);
});

it('escribir el nombre de una etiqueta existente, con otras mayúsculas, reutiliza la misma', function (): void {
    $tenant = ticketTenant();
    makeTag($tenant, ['name' => 'VIP', 'tone' => TagTone::Success]);
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/tags", ['name' => 'vip', 'tone' => 'danger'])->assertRedirect();

    expect(tagsOf($tenant))->toHaveCount(1)
        ->and(tagsOf($tenant)->first())->name->toBe('VIP')->tone->toBe(TagTone::Success)
        ->and(tagNamesOn($ticket))->toBe(['VIP']);
});

it('limpia los espacios repetidos del nombre', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/tags", ['name' => "  Soporte   \t técnico  "])->assertRedirect();

    expect(tagNamesOn($ticket))->toBe(['Soporte técnico']);
});

it('poner dos veces la misma etiqueta no la duplica', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    $agent = ticketStaff($tenant);

    $this->actingAs($agent)->post("/tickets/{$ticket->id}/tags", ['name' => 'VIP'])->assertRedirect();
    $this->actingAs($agent)->post("/tickets/{$ticket->id}/tags", ['name' => 'VIP'])->assertRedirect()->assertSessionHasNoErrors();

    expect(tagNamesOn($ticket))->toBe(['VIP'])->and(tagsOf($tenant))->toHaveCount(1);
});

it('el nombre se guarda como texto, no como HTML', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    $agent = ticketStaff($tenant);

    $this->actingAs($agent)->post("/tickets/{$ticket->id}/tags", ['name' => '<b>x</b>'])->assertRedirect();

    $this->actingAs($agent)->get("/tickets/{$ticket->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('selected.tags.0.name', '<b>x</b>'));
});

it('rechaza nombres vacíos, largos o con saltos de línea', function (mixed $name): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/tags", ['name' => $name])->assertSessionHasErrors('name');

    expect(tagsOf($tenant))->toHaveCount(0);
})->with([
    'vacío' => [''],
    'espacios' => ['   '],
    'sin campo' => [null],
    '31 caracteres' => [str_repeat('a', 31)],
    'salto de línea' => ["a\u{0}b"],
    'una lista' => [['a']],
]);

it('acepta un nombre de exactamente 30 caracteres', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/tags", ['name' => str_repeat('a', 30)])->assertRedirect()->assertSessionHasNoErrors();
});

it('solo admite tonos de la lista cerrada, nunca un color ni CSS', function (string $tone): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/tags", ['name' => 'VIP', 'tone' => $tone])->assertSessionHasErrors('tone');

    expect(tagsOf($tenant))->toHaveCount(0);
})->with(['#ff0000', 'red', 'red;background:url(//evil)', 'Success', 'accent']);

it('un ticket admite hasta 10 etiquetas', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    $agent = ticketStaff($tenant);

    foreach (range(1, 10) as $i) {
        $this->actingAs($agent)->post("/tickets/{$ticket->id}/tags", ['name' => "Etiqueta {$i}"])->assertRedirect()->assertSessionHasNoErrors();
    }
    $response = $this->actingAs($agent)->post("/tickets/{$ticket->id}/tags", ['name' => 'Etiqueta 11']);

    $response->assertSessionHasErrors('name');
    expect(session('errors')?->first('name'))->toBe('Un ticket admite hasta 10 etiquetas. Quita alguna para añadir otra.')
        ->and(tagNamesOn($ticket))->toHaveCount(10);
});

it('una empresa admite hasta 100 etiquetas, pero reutilizar una existente sigue funcionando', function (): void {
    $tenant = ticketTenant();
    foreach (range(1, 100) as $i) {
        makeTag($tenant, ['name' => "Tag {$i}"]);
    }
    $ticket = makeTicket($tenant);
    $agent = ticketStaff($tenant);

    $this->actingAs($agent)->post("/tickets/{$ticket->id}/tags", ['name' => 'Nueva'])->assertSessionHasErrors('name');
    $this->actingAs($agent)->post("/tickets/{$ticket->id}/tags", ['name' => 'tag 7'])->assertRedirect()->assertSessionHasNoErrors();

    expect(tagsOf($tenant))->toHaveCount(100)->and(tagNamesOn($ticket))->toBe(['Tag 7']);
});

it('el tope de etiquetas de una empresa no afecta a otra', function (): void {
    $full = ticketTenant();
    foreach (range(1, 100) as $i) {
        makeTag($full, ['name' => "Tag {$i}"]);
    }
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/tags", ['name' => 'Tag 1'])->assertRedirect()->assertSessionHasNoErrors();

    expect(tagsOf($tenant))->toHaveCount(1);
});

it('quita una etiqueta del ticket sin borrarla de la empresa', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    $agent = ticketStaff($tenant);
    $this->actingAs($agent)->post("/tickets/{$ticket->id}/tags", ['name' => 'VIP'])->assertRedirect();
    $tag = tagsOf($tenant)->firstOrFail();

    $this->actingAs($agent)->delete("/tickets/{$ticket->id}/tags/{$tag->id}")->assertRedirect();

    expect(tagNamesOn($ticket))->toBe([])->and(tagsOf($tenant))->toHaveCount(1);
});

it('quitar una etiqueta que el ticket no tiene no falla', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    $tag = makeTag($tenant);

    $this->actingAs(ticketStaff($tenant))->delete("/tickets/{$ticket->id}/tags/{$tag->id}")->assertRedirect();
});

it('matriz de permisos para etiquetar y quitar etiquetas', function (Role $role, int $status): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    $tag = makeTag($tenant);
    $user = ticketStaff($tenant, $role);

    $this->actingAs($user)->post("/tickets/{$ticket->id}/tags", ['name' => 'VIP'])->assertStatus($status);
    $this->actingAs($user)->delete("/tickets/{$ticket->id}/tags/{$tag->id}")->assertStatus($status);
})->with([
    'administrador' => [Role::Admin, 302],
    'agente' => [Role::Agent, 302],
    'observador' => [Role::Observer, 403],
]);

describe('aislamiento entre empresas', function (): void {
    it('no se puede etiquetar un ticket de otra empresa', function (): void {
        $ticket = makeTicket(ticketTenant());
        $intruder = ticketStaff(ticketTenant(), Role::Admin);

        $this->assertOtherTenantGets404($intruder, 'POST', "/tickets/{$ticket->id}/tags", ['name' => 'VIP']);
    });

    it('no se puede usar la etiqueta de otra empresa en un ticket propio', function (): void {
        $foreign = makeTag(ticketTenant(), ['name' => 'Ajena']);
        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);

        $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/tags", ['tag_id' => $foreign->id])->assertSessionHasErrors('tag_id');

        expect(tagNamesOn($ticket))->toBe([]);
    });

    it('no se puede quitar la etiqueta de otra empresa ni de un ticket ajeno', function (): void {
        $other = ticketTenant();
        $foreign = makeTag($other, ['name' => 'Ajena']);
        $foreignTicket = makeTicket($other);
        $this->actingAs(ticketStaff($other))->post("/tickets/{$foreignTicket->id}/tags", ['tag_id' => $foreign->id])->assertRedirect();

        $tenant = ticketTenant();
        $ticket = makeTicket($tenant);
        $intruder = ticketStaff($tenant);

        $this->assertOtherTenantGets404($intruder, 'DELETE', "/tickets/{$foreignTicket->id}/tags/{$foreign->id}");
        $this->actingAs($intruder)->delete("/tickets/{$ticket->id}/tags/{$foreign->id}")->assertNotFound();

        expect(tagNamesOn($foreignTicket))->toBe(['Ajena']);
    });

    it('dos empresas pueden tener una etiqueta con el mismo nombre sin mezclarse', function (): void {
        $a = ticketTenant();
        $b = ticketTenant();
        $ticketA = makeTicket($a);
        $ticketB = makeTicket($b);

        $this->actingAs(ticketStaff($a))->post("/tickets/{$ticketA->id}/tags", ['name' => 'VIP', 'tone' => 'success'])->assertRedirect();
        $this->actingAs(ticketStaff($b))->post("/tickets/{$ticketB->id}/tags", ['name' => 'VIP', 'tone' => 'danger'])->assertRedirect();

        expect(tagsOf($a))->toHaveCount(1)->and(tagsOf($a)->first())->tone->toBe(TagTone::Success)
            ->and(tagsOf($b)->first())->tone->toBe(TagTone::Danger);
    });

    it('las etiquetas que ofrece la pantalla son solo las de la propia empresa', function (): void {
        $tenant = ticketTenant();
        makeTag($tenant, ['name' => 'Propia']);
        makeTag(ticketTenant(), ['name' => 'Ajena']);
        $ticket = makeTicket($tenant);

        $this->actingAs(ticketStaff($tenant))->get("/tickets/{$ticket->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->has('tag_options', 1)->where('tag_options.0.name', 'Propia'));
    });
});

it('el observador ve las etiquetas del ticket pero no recibe la lista para elegir', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);
    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/tags", ['name' => 'VIP'])->assertRedirect();

    $this->actingAs(ticketStaff($tenant, Role::Observer))->get("/tickets/{$ticket->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->has('selected.tags', 1)->where('selected.tags.0.name', 'VIP')->has('tag_options', 0));
});

it('un visitante y un usuario desactivado no etiquetan', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant);

    $this->post("/tickets/{$ticket->id}/tags", ['name' => 'VIP'])->assertRedirect('/login');
    $this->actingAs(ticketStaff($tenant, Role::Agent, disabled: true))->post("/tickets/{$ticket->id}/tags", ['name' => 'VIP'])->assertRedirect(route('login'));
});

it('etiquetar un ticket cerrado está permitido', function (): void {
    $tenant = ticketTenant();
    $ticket = makeTicket($tenant, ['status' => TicketStatus::Closed]);

    $this->actingAs(ticketStaff($tenant))->post("/tickets/{$ticket->id}/tags", ['name' => 'Revisado'])->assertRedirect()->assertSessionHasNoErrors();
});
