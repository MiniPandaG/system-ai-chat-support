<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Branding\BrandColor;
use App\Branding\Palette;
use App\Enums\AuditEvent;
use App\Files\FilePurpose;
use App\Files\FileRejected;
use App\Files\FileStore;
use App\Http\Requests\UpdateCompanyNameRequest;
use App\Http\Requests\UpdatePaletteRequest;
use App\Http\Requests\UploadLogoRequest;
use App\Models\TenantBranding;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Marca de la empresa: logo y nombre. Solo administradores (TenantBrandingPolicy).
 */
class BrandingController extends Controller
{
    public function __construct(
        private readonly FileStore $files,
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function show(): Response
    {
        Gate::authorize('manage', TenantBranding::class);

        $branding = TenantBranding::current();
        $logo = $branding->logo();
        $report = Palette::check($branding->colors ?? []);

        return Inertia::render('settings/Branding', [
            'copy' => __('branding'),
            'name' => $this->context->current()->name ?? '',
            'logo' => $logo === null ? null : [
                'url' => $this->files->temporaryUrl($logo),
                'width' => $logo->width,
                'height' => $logo->height,
            ],
            'palette' => [
                'colors' => $report->colors,
                'foregrounds' => $report->foregrounds,
                'defaults' => collect(BrandColor::cases())->mapWithKeys(fn (BrandColor $color): array => [$color->value => strtoupper($color->defaultHex())])->all(),
            ],
            'limits' => [
                'max_bytes' => config()->integer('files.image.max_bytes'),
                'accepted' => ['image/png', 'image/jpeg', 'image/webp'],
            ],
        ]);
    }

    public function updateName(UpdateCompanyNameRequest $request): RedirectResponse
    {
        $tenant = $this->context->current();
        abort_if($tenant === null, 404);

        $name = $request->string('name')->toString();

        if ($tenant->name !== $name) {
            $tenant->forceFill(['name' => $name])->save();
            $this->audit->record(AuditEvent::BrandingNameChanged, $request->user()?->id);
        }

        return back()->with('status', __('branding.name_saved'));
    }

    /**
     * Revisa una paleta sin guardarla: contraste, texto calculado y, si algo no cumple, el tono más cercano que sí.
     * Es lo que el selector de colores consulta mientras la empresa elige.
     */
    public function checkPalette(Request $request): JsonResponse
    {
        Gate::authorize('manage', TenantBranding::class);

        $colors = $request->input('colors');

        return response()->json(Palette::check(is_array($colors) ? $colors : [])->toArray());
    }

    public function updateColors(UpdatePaletteRequest $request): RedirectResponse
    {
        /** @var array<string, string> $submitted */
        $submitted = $request->validated('colors');
        $report = Palette::check($submitted);

        if (! $report->passes()) {
            $messages = [];

            foreach ($report->issues as $issue) {
                $messages['colors.'.$issue->key][] = $issue->message;
            }

            throw ValidationException::withMessages($messages);
        }

        $stored = Palette::forStorage($submitted);
        TenantBranding::current()->forceFill($stored)->save();

        $this->audit->record(AuditEvent::BrandingPaletteChanged, $request->user()?->id, ['changed' => implode(',', array_keys($stored['colors'] ?? []))]);

        return back()->with('status', __('branding.palette_saved'));
    }

    /**
     * Devuelve la paleta a los valores de fábrica. Es la acción que la pantalla confirma antes de pedirla.
     */
    public function resetColors(Request $request): RedirectResponse
    {
        Gate::authorize('manage', TenantBranding::class);

        TenantBranding::current()->forceFill(Palette::forStorage([]))->save();

        $this->audit->record(AuditEvent::BrandingPaletteReset, $request->user()?->id);

        return back()->with('status', __('branding.palette_reset'));
    }

    public function storeLogo(UploadLogoRequest $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $upload = $request->file('logo');
        abort_unless($upload instanceof UploadedFile, 422);

        try {
            $new = $this->files->storeImage($upload, FilePurpose::Logo, $user);
        } catch (FileRejected $rejection) {
            throw ValidationException::withMessages(['logo' => [$rejection->getMessage()]]);
        }

        $previous = DB::transaction(function () use ($new) {
            $branding = TenantBranding::current();
            $previous = $branding->logo();
            $branding->forceFill(['logo_file_id' => $new->id])->save();

            return $previous;
        });

        // El logo anterior ya no se usa en ninguna parte: se borra del disco y de la base.
        if ($previous !== null) {
            $this->files->delete($previous, $user);
        }

        $this->audit->record(AuditEvent::BrandingLogoChanged, $user->id, ['file_id' => $new->id]);

        return back()->with('status', __('branding.logo_saved'));
    }

    public function destroyLogo(Request $request): RedirectResponse
    {
        Gate::authorize('manage', TenantBranding::class);

        $branding = TenantBranding::current();
        $logo = $branding->logo();

        if ($logo !== null) {
            $branding->forceFill(['logo_file_id' => null])->save();
            $this->files->delete($logo, $request->user());
            $this->audit->record(AuditEvent::BrandingLogoRemoved, $request->user()?->id);
        }

        return back()->with('status', __('branding.logo_removed'));
    }
}
