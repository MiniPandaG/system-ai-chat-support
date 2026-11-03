<?php

declare(strict_types=1);

namespace App\Providers;

use App\Attachments\ClamAvScanner;
use App\Attachments\FakeScanner;
use App\Attachments\Scanner;
use App\Branding\Palette;
use App\Knowledge\Vector\Embedder;
use App\Knowledge\Vector\FakeEmbedder;
use App\Knowledge\Vector\FakeIndex;
use App\Knowledge\Vector\QdrantIndex;
use App\Knowledge\Vector\TeiEmbedder;
use App\Knowledge\Vector\VectorIndex;
use App\Mail\Inbound\ImapMailboxReader;
use App\Mail\Inbound\MailboxReader;
use App\Mail\Inbound\NullMailboxReader;
use App\Models\TenantBranding;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantJobContext;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(TenantJobContext::class);

        // El buzón de soporte: sin servidor IMAP configurado no se lee nada.
        $this->app->bind(MailboxReader::class, function (): MailboxReader {
            $settings = config()->array('mailbox.imap');

            if (! is_string($settings['host'] ?? null) || $settings['host'] === '') {
                return new NullMailboxReader;
            }

            return new ImapMailboxReader([
                'host' => $settings['host'],
                'port' => is_int($settings['port'] ?? null) ? $settings['port'] : 993,
                'encryption' => is_string($settings['encryption'] ?? null) ? $settings['encryption'] : null,
                'validate_cert' => (bool) ($settings['validate_cert'] ?? true),
                'username' => is_string($settings['username'] ?? null) ? $settings['username'] : null,
                'password' => is_string($settings['password'] ?? null) ? $settings['password'] : null,
                'folder' => is_string($settings['folder'] ?? null) ? $settings['folder'] : 'INBOX',
            ]);
        });

        // El índice vectorial es uno solo por proceso: así el de pruebas guarda lo que se le pone entre una llamada y otra.
        $this->app->singleton(VectorIndex::class, function (): VectorIndex {
            $driver = config()->string('knowledge.index.driver');

            return match (true) {
                $driver === 'qdrant' => new QdrantIndex(
                    $this->app->make(HttpFactory::class),
                    config()->string('knowledge.index.url'),
                    config()->string('knowledge.index.api_key'),
                    config()->integer('knowledge.index.timeout'),
                ),
                // El índice de mentira vive en memoria y no cuida nada: en producción sería perder todo el conocimiento.
                $driver === 'fake' && ! $this->app->isProduction() => new FakeIndex,
                default => throw new LogicException("KNOWLEDGE_INDEX=\"{$driver}\" no es válido en este entorno: usa «qdrant»."),
            };
        });

        $this->app->bind(Embedder::class, function (): Embedder {
            $driver = config()->string('knowledge.embeddings.driver');

            return match (true) {
                $driver === 'tei' => new TeiEmbedder(
                    $this->app->make(HttpFactory::class),
                    config()->string('knowledge.embeddings.url'),
                    config()->integer('knowledge.embeddings.dimensions'),
                    config()->integer('knowledge.embeddings.batch'),
                    config()->integer('knowledge.embeddings.timeout'),
                    config()->string('knowledge.embeddings.prefix_query'),
                    config()->string('knowledge.embeddings.prefix_passage'),
                ),
                $driver === 'fake' && ! $this->app->isProduction() => new FakeEmbedder,
                default => throw new LogicException("KNOWLEDGE_EMBEDDER=\"{$driver}\" no es válido en este entorno: usa «tei».\n"),
            };
        });

        $this->app->bind(Scanner::class, function (): Scanner {
            $driver = config()->string('attachments.scanner');

            return match (true) {
                $driver === 'clamav' => new ClamAvScanner(
                    config()->string('attachments.clamav.host'),
                    config()->integer('attachments.clamav.port'),
                    config()->integer('attachments.clamav.timeout'),
                ),
                // El antivirus de mentira da por limpio casi todo: en producción sería una puerta abierta.
                $driver === 'fake' && ! $this->app->isProduction() => new FakeScanner,
                default => throw new LogicException("ATTACHMENT_SCANNER=\"{$driver}\" no es válido en este entorno: usa «clamav»."),
            };
        });
    }

    public function boot(): void
    {
        $this->app->make(TenantJobContext::class)->register();

        // Paleta de la empresa en la primera carga de la página, para que no parpadee el naranja de fábrica.
        View::composer('app', function (ViewContract $view): void {
            $branding = app(TenantContext::class)->id() === null ? null : TenantBranding::query()->first();

            $view->with('brandingCss', Palette::css($branding?->panelVariables() ?? []));
        });

        // Un miembro solo se resuelve dentro de la empresa en contexto: otra empresa recibe 404, no 403.
        Route::bind('member', function (string $value): User {
            $tenantId = app(TenantContext::class)->id();
            abort_if($tenantId === null || ! ctype_digit($value), 404);

            return User::query()->inTenant($tenantId)->findOrFail((int) $value);
        });

        Model::shouldBeStrict(! $this->app->isProduction());
        DB::prohibitDestructiveCommands($this->app->isProduction());

        if ($this->app->isProduction()) {
            URL::forceHttps();
        }
    }
}
