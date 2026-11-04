<?php

declare(strict_types=1);

require_once __DIR__.'/Helpers.php';

use App\Csat\Surveys;
use App\Csat\SurveyToken;
use App\Jobs\SendSurveyEmail;
use App\Mail\SurveyEmail;
use App\Mail\Threads;
use App\Models\CsatResponse;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\Ticket;
use App\Tenancy\TenantContext;
use App\Tickets\Channel;
use App\Tickets\TicketStatus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    // Los límites de peticiones se miden por dirección y el conjunto de pruebas comparte la suya: aquí se ensanchan.
    config(['csat.limits.show_per_ip_per_minute' => 10000, 'csat.limits.answer_per_ip_per_minute' => 10000, 'mail.from.address' => 'soporte@tikarochat.test']);
});

describe('cuándo se ofrece la encuesta', function (): void {
    it('al resolver un ticket con correo se crea la encuesta con la huella del token, caducidad a 14 días y un correo en cola', function (): void {
        $tenant = ticketTenant();
        $ticket = mailTicket($tenant);

        $token = csatResolve($tenant, $ticket);

        $row = csatRow($tenant, $ticket);
        expect($token)->toBeString()->and($row)->not->toBeNull()->and($row?->token_hash)->toBe(hash('sha256', (string) $token))->and($row?->answered_at)->toBeNull()->and($row?->score)->toBeNull()
            ->and($row?->expires_at->between(now()->addDays(14)->subMinute(), now()->addDays(14)->addMinute()))->toBeTrue()
            ->and(json_encode($row?->getAttributes()))->not->toContain((string) $token);
        Queue::assertPushed(SendSurveyEmail::class, fn (SendSurveyEmail $job): bool => $job->csatId === $row?->id);
    });

    it('al cerrar un ticket también, y solo una vez aunque se reabra y se vuelva a resolver', function (): void {
        $tenant = ticketTenant();
        $ticket = mailTicket($tenant);

        csatResolve($tenant, $ticket, TicketStatus::Closed);
        $first = csatRow($tenant, $ticket);
        csatSetStatus($tenant, $ticket, TicketStatus::Open);
        $second = csatResolve($tenant, $ticket);
        csatSetStatus($tenant, $ticket, TicketStatus::Resolved);

        expect($first)->not->toBeNull()->and($second)->toBeNull()->and(csatRow($tenant, $ticket)?->id)->toBe($first?->id)
            ->and(app(TenantContext::class)->run($tenant, fn (): int => CsatResponse::query()->count()))->toBe(1);
    });

    it('un estado que no es resuelto ni cerrado no ofrece nada', function (TicketStatus $status): void {
        $tenant = ticketTenant();
        $ticket = mailTicket($tenant);

        csatResolve($tenant, $ticket, $status);

        expect(csatRow($tenant, $ticket))->toBeNull();
    })->with([TicketStatus::Open, TicketStatus::WaitingCustomer]);

    it('sin correo solo se ofrece si el ticket nació en el chat web, y entonces sin correo en cola', function (): void {
        $tenant = ticketTenant();
        $noEmail = app(TenantContext::class)->run($tenant, fn () => Customer::factory()->create(['email' => null]));
        $panel = makeTicket($tenant, ['channel' => Channel::Panel], $noEmail);
        $widget = makeTicket($tenant, ['channel' => Channel::Widget], $noEmail);

        $panelToken = csatResolve($tenant, $panel);
        $widgetToken = csatResolve($tenant, $widget);

        expect(csatRow($tenant, $panel))->toBeNull()->and($panelToken)->toBeNull()->and(csatRow($tenant, $widget))->not->toBeNull()->and($widgetToken)->toBeNull();
    });

    it('resolver un ticket por la interfaz del panel también dispara la encuesta', function (): void {
        $tenant = ticketTenant();
        $ticket = mailTicket($tenant);
        Queue::fake();

        $this->actingAs(ticketStaff($tenant))->patch("/tickets/{$ticket->id}", ['status' => 'resolved'])->assertSessionHasNoErrors();

        expect(csatRow($tenant, $ticket))->not->toBeNull();
        Queue::assertPushed(SendSurveyEmail::class);
    });

    it('cada encuesta tiene su propio token y el de una empresa no abre la de otra', function (): void {
        $one = ticketTenant();
        $two = ticketTenant();
        [, $tokenOne] = csatResolvedTicket($one);
        [, $tokenTwo] = csatResolvedTicket($two, 'luis@cliente-ejemplo.com', 'Luis Pérez');

        $swapped = $two->id.'.'.substr($tokenOne, strpos($tokenOne, '.') + 1);

        expect($tokenOne)->not->toBe($tokenTwo)->and(str_starts_with($tokenOne, $one->id.'.'))->toBeTrue()
            ->and($this->post("/survey/{$swapped}", ['score' => 5])->assertRedirect(route('survey.show', ['token' => $swapped])))
            ->and(app(TenantContext::class)->run($one, fn () => CsatResponse::query()->first())?->answered_at)->toBeNull();
    });
});

describe('el token', function (): void {
    it('solo acepta la forma exacta: empresa, punto y 43 caracteres', function (?string $token): void {
        expect(SurveyToken::parse($token))->toBeNull();
    })->with([
        'nulo' => [null],
        'vacío' => [''],
        'sin empresa' => ['.'.str_repeat('a', 43)],
        'empresa con cero a la izquierda' => ['07.'.str_repeat('a', 43)],
        'corto' => ['7.'.str_repeat('a', 42)],
        'largo' => ['7.'.str_repeat('a', 44)],
        'con símbolos' => ['7.'.str_repeat('a', 42).'-'],
        'con salto de línea al final' => ['7.'.str_repeat('a', 43)."\n"],
        'empresa no numérica' => ['x.'.str_repeat('a', 43)],
    ]);

    it('un token bien formado lleva la empresa y la huella SHA-256', function (): void {
        $issued = SurveyToken::issue(7);
        $parsed = SurveyToken::parse($issued['token']);

        expect($parsed)->toBe(['tenant_id' => 7, 'hash' => $issued['hash']])->and($issued['hash'])->toBe(hash('sha256', $issued['token']))->and(strlen($issued['token']))->toBe(45);
    });
});

describe('abrir el enlace', function (): void {
    it('muestra el formulario con la empresa y el puntaje que traía el enlace, y no consume nada', function (): void {
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);

        $response = $this->get("/survey/{$token}?score=4")->assertOk();
        $this->get("/survey/{$token}?score=2")->assertOk();
        $this->get("/survey/{$token}")->assertOk();

        $page = $response->viewData('page');
        expect($page['component'])->toBe('survey/Show')->and($page['props']['open'])->toBeTrue()->and($page['props']['company'])->toBe($tenant->name)->and($page['props']['score'])->toBe(4)
            ->and(csatRow($tenant, $ticket)?->answered_at)->toBeNull()->and(csatRow($tenant, $ticket)?->score)->toBeNull();
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->and($response->headers->get('Referrer-Policy'))->toBe('no-referrer');
    });

    it('un puntaje raro en el enlace se ignora', function (string $score): void {
        $tenant = ticketTenant();
        [, $token] = csatResolvedTicket($tenant);

        $page = $this->get("/survey/{$token}?score={$score}")->assertOk()->viewData('page');

        expect($page['props']['score'])->toBeNull();
    })->with(['0', '6', '-1', 'abc', '3.5', '']);

    it('un enlace falso, caducado o ya usado muestra exactamente la misma página de «ya no sirve»', function (): void {
        $tenant = ticketTenant();
        [$expiredTicket, $expiredToken] = csatResolvedTicket($tenant);
        [, $usedToken] = csatResolvedTicket($tenant, 'luis@cliente-ejemplo.com', 'Luis Pérez');
        $this->post("/survey/{$usedToken}", ['score' => 5]);
        app(TenantContext::class)->run($tenant, fn () => CsatResponse::query()->where('ticket_id', $expiredTicket->id)->update(['expires_at' => now()->subSecond()]));

        $closed = [
            'falso con la forma correcta' => $tenant->id.'.'.str_repeat('a', 43),
            'forma incorrecta' => 'cualquier-cosa',
            'empresa que no existe' => '999999.'.str_repeat('a', 43),
            'caducado' => $expiredToken,
            'ya usado' => $usedToken,
        ];

        foreach ($closed as $label => $token) {
            $page = $this->get("/survey/{$token}")->assertOk()->viewData('page');
            expect($page['props'])->toMatchArray(['open' => false, 'company' => '', 'theme' => [], 'score' => null], $label);
        }
    });

    it('no revela nada de la empresa ni del ticket cuando el enlace no sirve', function (): void {
        $tenant = ticketTenant();
        $this->get('/survey/'.$tenant->id.'.'.str_repeat('z', 43))->assertOk()->assertDontSee($tenant->name);
    });
});

describe('responder', function (): void {
    it('registra el puntaje y el comentario una sola vez y agradece', function (): void {
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);

        $this->post("/survey/{$token}", ['score' => 4, 'comment' => '  Me atendieron rápido.  '])->assertRedirect(route('survey.thanks'));

        $row = csatRow($tenant, $ticket);
        expect($row?->score)->toBe(4)->and($row?->comment)->toBe('Me atendieron rápido.')->and($row?->channel)->toBe('email')->and($row?->answered_at)->not->toBeNull();
        $this->get('/survey/thanks')->assertOk()->assertInertia(fn ($page) => $page->component('survey/Thanks'));
    });

    it('el token sirve una sola vez: una segunda respuesta no cambia la primera', function (): void {
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);

        $this->post("/survey/{$token}", ['score' => 5, 'comment' => 'Excelente']);
        $again = $this->post("/survey/{$token}", ['score' => 1, 'comment' => 'Cambié de opinión']);

        $row = csatRow($tenant, $ticket);
        expect($again->isRedirect(route('survey.show', ['token' => $token])))->toBeTrue()->and($row?->score)->toBe(5)->and($row?->comment)->toBe('Excelente');
        expect($this->get("/survey/{$token}")->viewData('page')['props']['open'])->toBeFalse();
    });

    it('una encuesta caducada ya no se puede responder', function (): void {
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);
        app(TenantContext::class)->run($tenant, fn () => CsatResponse::query()->update(['expires_at' => now()->subMinute()]));

        $this->post("/survey/{$token}", ['score' => 5]);

        expect(csatRow($tenant, $ticket)?->score)->toBeNull()->and(csatRow($tenant, $ticket)?->answered_at)->toBeNull();
    });

    it('un comentario en blanco se guarda vacío y el comentario es opcional', function (?string $comment): void {
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);

        $this->post("/survey/{$token}", ['score' => 3, 'comment' => $comment])->assertRedirect(route('survey.thanks'));

        expect(csatRow($tenant, $ticket)?->comment)->toBeNull()->and(csatRow($tenant, $ticket)?->score)->toBe(3);
    })->with([null, '', '   ']);

    it('el comentario es texto: caracteres de control e invisibles se quitan y el HTML queda tal cual para mostrarse escapado', function (): void {
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);

        $this->post("/survey/{$token}", ['score' => 2, 'comment' => "Mal\u{200B} servicio <script>alert(1)</script>\u{202E}\x01 y <b>tarde</b>"]);

        expect(csatRow($tenant, $ticket)?->comment)->toBe('Mal servicio <script>alert(1)</script> y <b>tarde</b>');
    });

    it('rechaza lo que no es un puntaje de 1 a 5 o un comentario demasiado largo, y la encuesta sigue abierta', function (array $payload, string $field): void {
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);

        $this->post("/survey/{$token}", $payload)->assertSessionHasErrors($field);

        expect(csatRow($tenant, $ticket)?->answered_at)->toBeNull();
        $this->post("/survey/{$token}", ['score' => 5])->assertRedirect(route('survey.thanks'));
    })->with([
        'sin puntaje' => [[], 'score'],
        'cero' => [['score' => 0], 'score'],
        'seis' => [['score' => 6], 'score'],
        'negativo' => [['score' => -3], 'score'],
        'texto' => [['score' => 'cinco'], 'score'],
        'decimal' => [['score' => 4.5], 'score'],
        'lista' => [['score' => [5]], 'score'],
        'comentario de 1001 caracteres' => [['score' => 5, 'comment' => str_repeat('a', 1001)], 'comment'],
        'comentario que no es texto' => [['score' => 5, 'comment' => ['a']], 'comment'],
    ]);

    it('un comentario de exactamente el máximo se acepta', function (): void {
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);

        $this->post("/survey/{$token}", ['score' => 5, 'comment' => str_repeat('a', 1000)])->assertRedirect(route('survey.thanks'));

        expect(mb_strlen((string) csatRow($tenant, $ticket)?->comment))->toBe(1000);
    });

    it('lo que el cliente intente mandar de más (empresa, ticket, canal, fechas) se ignora', function (): void {
        $tenant = ticketTenant();
        $other = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);

        $this->post("/survey/{$token}", ['score' => 5, 'tenant_id' => $other->id, 'ticket_id' => 999, 'channel' => 'widget', 'answered_at' => '2001-01-01', 'sent_at' => '2001-01-01']);

        $row = csatRow($tenant, $ticket);
        expect($row?->tenant_id)->toBe($tenant->id)->and($row?->ticket_id)->toBe($ticket->id)->and($row?->channel)->toBe('email')->and($row?->answered_at?->year)->toBe((int) now()->year)
            ->and(app(TenantContext::class)->run($other, fn (): int => CsatResponse::query()->count()))->toBe(0);
    });

    it('dos respuestas a la vez dejan solo una: la fila se bloquea y la segunda ve la encuesta ya usada', function (): void {
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);
        $surveys = app(Surveys::class);

        $first = $surveys->answerByToken($token, 5, 'Primera');
        $second = $surveys->answerByToken($token, 1, 'Segunda');

        expect($first)->toBeTrue()->and($second)->toBeFalse()->and(csatRow($tenant, $ticket)?->comment)->toBe('Primera');
    });

    it('la respuesta funciona aunque quien responde tenga una sesión del panel iniciada', function (): void {
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);

        $this->actingAs(ticketStaff($tenant))->post("/survey/{$token}", ['score' => 5])->assertRedirect(route('survey.thanks'));

        expect(csatRow($tenant, $ticket)?->score)->toBe(5);
    });
});

describe('límites de peticiones', function (): void {
    it('después de varios envíos desde una misma dirección responde 429', function (): void {
        config(['csat.limits.answer_per_ip_per_minute' => 3]);
        RateLimiter::clear('survey-answer');
        $tenant = ticketTenant();
        $address = ['REMOTE_ADDR' => '10.77.'.random_int(1, 250).'.'.random_int(1, 250)];

        $statuses = [];
        foreach (range(1, 5) as $_) {
            $statuses[] = $this->withServerVariables($address)->post('/survey/'.$tenant->id.'.'.str_repeat('a', 43), ['score' => 5])->getStatusCode();
        }

        expect(array_slice($statuses, 0, 3))->each->toBe(302)->and(array_slice($statuses, 3))->each->toBe(429);
    });

    it('abrir el enlace también tiene tope por dirección', function (): void {
        config(['csat.limits.show_per_ip_per_minute' => 2]);
        $tenant = ticketTenant();
        $address = ['REMOTE_ADDR' => '10.78.'.random_int(1, 250).'.'.random_int(1, 250)];

        $statuses = [];
        foreach (range(1, 4) as $_) {
            $statuses[] = $this->withServerVariables($address)->get('/survey/'.$tenant->id.'.'.str_repeat('a', 43))->getStatusCode();
        }

        expect($statuses)->toBe([200, 200, 429, 429]);
    });
});

describe('el correo', function (): void {
    function surveyJob(Tenant $tenant, Ticket $ticket, string $token): void
    {
        $row = csatRow($tenant, $ticket);
        app(TenantContext::class)->run($tenant, fn () => app()->call([new SendSurveyEmail((int) $row?->id, $token), 'handle']));
    }

    it('sale con cinco enlaces, uno por puntaje, que abren el formulario con ese puntaje marcado', function (): void {
        Mail::fake();
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);

        surveyJob($tenant, $ticket, $token);

        Mail::assertSent(SurveyEmail::class, function (SurveyEmail $mail) use ($token, $tenant): bool {
            $links = $mail->links();
            $html = $mail->render();

            return $mail->hasTo('marisol@cliente-ejemplo.com')
                && array_keys($links) === [1, 2, 3, 4, 5]
                && $links[3] === url("/survey/{$token}").'?score=3'
                && substr_count($html, '/survey/'.$token.'?score=') === 5
                && str_contains($html, $tenant->name)
                && str_contains($html, 'Marisol')
                && $mail->envelope()->subject === 'Cuéntanos cómo te fue con tu ticket #1'
                && $mail->hasReplyTo(config('mail.from.address')) === false;
        });
    });

    it('la versión de texto lleva los mismos enlaces y el nombre y la empresa salen escapados', function (): void {
        Mail::fake();
        $tenant = ticketTenant();
        $tenant->forceFill(['name' => 'Zapatería <b>Ejemplo</b>'])->save();
        [$ticket, $token] = csatResolvedTicket($tenant, 'ana@cliente-ejemplo.com', '<script>alert(1)</script>Ana');

        surveyJob($tenant->refresh(), $ticket, $token);

        Mail::assertSent(SurveyEmail::class, function (SurveyEmail $mail) use ($token): bool {
            $html = $mail->render();

            return ! str_contains($html, '<script>') && ! str_contains($html, '<b>Ejemplo') && str_contains($html, '&lt;b&gt;Ejemplo') && substr_count($html, $token) === 5;
        });
    });

    it('al visitante sin nombre real se le saluda sin nombre', function (): void {
        Mail::fake();
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant, 'visita@cliente-ejemplo.com', 'Visitante AB12');

        surveyJob($tenant, $ticket, $token);

        Mail::assertSent(SurveyEmail::class, fn (SurveyEmail $mail): bool => $mail->greetingName() === '' && ! str_contains($mail->render(), 'AB12'));
    });

    it('no sale si ya se respondió, si caducó o si el cliente ya no tiene correo', function (string $case): void {
        Mail::fake();
        $tenant = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($tenant);

        match ($case) {
            'respondida' => app(Surveys::class)->answerByToken($token, 5, null),
            'caducada' => app(TenantContext::class)->run($tenant, fn () => CsatResponse::query()->update(['expires_at' => now()->subMinute()])),
            'sin correo' => app(TenantContext::class)->run($tenant, fn () => Customer::query()->update(['email' => null])),
        };

        surveyJob($tenant, $ticket, $token);

        Mail::assertNothingSent();
    })->with(['respondida', 'caducada', 'sin correo']);

    it('a una misma dirección se le mandan como mucho 3 encuestas por día', function (): void {
        Mail::fake();
        RateLimiter::clear('mail-survey:'.Threads::hash('repetido@cliente-ejemplo.com'));
        $tenant = ticketTenant();

        $customer = app(TenantContext::class)->run($tenant, fn () => Customer::factory()->create(['email' => 'repetido@cliente-ejemplo.com', 'name' => 'Marisol Quintero']));

        foreach (range(1, 5) as $_) {
            $ticket = makeTicket($tenant, [], $customer);
            $token = csatResolve($tenant, $ticket);
            surveyJob($tenant, $ticket, (string) $token);
        }

        Mail::assertSentCount(3);
    });

    it('si el ticket pasó a otra empresa no se mezcla: el trabajo de una empresa no ve la encuesta de otra', function (): void {
        Mail::fake();
        $one = ticketTenant();
        $two = ticketTenant();
        [$ticket, $token] = csatResolvedTicket($one);
        $row = csatRow($one, $ticket);

        app(TenantContext::class)->run($two, fn () => app()->call([new SendSurveyEmail((int) $row?->id, $token), 'handle']));

        Mail::assertNothingSent();
    });
});
