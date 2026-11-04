<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Models\CsatResponse;
use App\Tenancy\TenantContext;
use App\Tickets\TicketStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config(['widget.limits.survey_per_session_per_hour' => 1000]);
});

/** Un visitante del widget con una conversación ya resuelta. @return array{0: App\Models\Tenant, 1: string, 2: App\Models\Ticket} */
function resolvedWidgetVisit(): array
{
    $tenant = widgetTenant();
    $token = widgetStart(test(), $tenant);
    $ticket = widgetTicketOf($tenant, $token);
    Queue::fake();
    csatSetStatus($tenant, $ticket, TicketStatus::Resolved);

    return [$tenant, $token, $ticket];
}

function widgetSurveyPost(string $token, array $payload): TestResponse
{
    return test()->postJson('/widget/api/survey', $payload, ['Authorization' => 'Bearer '.$token]);
}

describe('la conversación avisa si hay una encuesta', function (): void {
    it('con el ticket resuelto hay una encuesta por contestar, y mientras está abierto no', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);
        $ticket = widgetTicketOf($tenant, $token);

        expect(widgetGet($this, 'conversation', $token)->assertOk()->json('survey'))->toBe(['pending' => false]);

        Queue::fake();
        csatSetStatus($tenant, $ticket, TicketStatus::Resolved);

        expect(widgetGet($this, 'conversation', $token)->assertOk()->json('survey'))->toBe(['pending' => true]);
    });

    it('después de contestar, o si caducó, ya no está pendiente', function (): void {
        [$tenant, $token] = resolvedWidgetVisit();
        widgetSurveyPost($token, ['score' => 5])->assertOk();

        expect(widgetGet($this, 'conversation', $token)->json('survey'))->toBe(['pending' => false]);

        [$otherTenant, $otherToken] = resolvedWidgetVisit();
        app(TenantContext::class)->run($otherTenant, fn () => CsatResponse::query()->update(['expires_at' => now()->subMinute()]));

        expect(widgetGet($this, 'conversation', $otherToken)->json('survey'))->toBe(['pending' => false]);
    });
});

describe('contestar desde el widget', function (): void {
    it('registra el puntaje y el comentario con el canal «widget» y solo una vez', function (): void {
        [$tenant, $token, $ticket] = resolvedWidgetVisit();

        widgetSurveyPost($token, ['score' => 3, 'comment' => '  Tardaron un poco.  '])->assertOk()->assertJson(['answered' => true]);
        widgetSurveyPost($token, ['score' => 1, 'comment' => 'Otra vez'])->assertNotFound();

        $row = csatRow($tenant, $ticket);
        expect($row?->score)->toBe(3)->and($row?->comment)->toBe('Tardaron un poco.')->and($row?->channel)->toBe('widget')->and($row?->answered_at)->not->toBeNull();
    });

    it('si ya se contestó por el enlace del correo, el widget no puede contestar otra vez', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant, ['email' => 'visita@cliente-ejemplo.com']);
        $ticket = widgetTicketOf($tenant, $token);
        $link = csatResolve($tenant, $ticket);
        expect($link)->toBeString();

        $this->post("/survey/{$link}", ['score' => 5])->assertRedirect(route('survey.thanks'));
        widgetSurveyPost($token, ['score' => 1])->assertNotFound();

        expect(csatRow($tenant, $ticket)?->score)->toBe(5)->and(csatRow($tenant, $ticket)?->channel)->toBe('email');
    });

    it('sin encuesta (el ticket sigue abierto) no hay nada que contestar', function (): void {
        $tenant = widgetTenant();
        $token = widgetStart($this, $tenant);

        widgetSurveyPost($token, ['score' => 5])->assertNotFound();

        expect(app(TenantContext::class)->run($tenant, fn (): int => CsatResponse::query()->count()))->toBe(0);
    });

    it('rechaza un puntaje fuera de 1 a 5 o un comentario demasiado largo y la encuesta sigue abierta', function (array $payload, string $field): void {
        [$tenant, $token, $ticket] = resolvedWidgetVisit();

        widgetSurveyPost($token, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);

        expect(csatRow($tenant, $ticket)?->answered_at)->toBeNull();
    })->with([
        'sin puntaje' => [[], 'score'],
        'cero' => [['score' => 0], 'score'],
        'seis' => [['score' => 6], 'score'],
        'texto' => [['score' => 'cinco'], 'score'],
        'comentario largo' => [['score' => 4, 'comment' => str_repeat('a', 1001)], 'comment'],
    ]);

    it('sin token, con uno falso o de otra empresa no se puede contestar', function (): void {
        [$tenant, $token, $ticket] = resolvedWidgetVisit();

        $this->postJson('/widget/api/survey', ['score' => 5])->assertUnauthorized();
        widgetSurveyPost($tenant->id.'.'.str_repeat('a', 43), ['score' => 5])->assertUnauthorized();
        widgetSurveyPost('999999.'.str_repeat('a', 43), ['score' => 5])->assertUnauthorized();

        expect(csatRow($tenant, $ticket)?->answered_at)->toBeNull();
    });

    it('cada visitante solo puede contestar la encuesta de su propio ticket', function (): void {
        [$tenantA, $tokenA, $ticketA] = resolvedWidgetVisit();
        [$tenantB, $tokenB, $ticketB] = resolvedWidgetVisit();

        widgetSurveyPost($tokenA, ['score' => 2, 'ticket_id' => $ticketB->id, 'tenant_id' => $tenantB->id])->assertOk();

        expect(csatRow($tenantA, $ticketA)?->score)->toBe(2)->and(csatRow($tenantB, $ticketB)?->answered_at)->toBeNull();
    });

    it('tiene tope de peticiones por sesión', function (): void {
        config(['widget.limits.survey_per_session_per_hour' => 2]);
        [, $token] = resolvedWidgetVisit();

        $statuses = [];
        foreach (range(1, 4) as $_) {
            $statuses[] = widgetSurveyPost($token, ['score' => 0])->getStatusCode();
        }

        expect($statuses)->toBe([422, 422, 429, 429]);
    });
});
