<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\RegisterTenant;
use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterRequest;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisterController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/Register', ['copy' => __('auth.register')]);
    }

    public function store(RegisterRequest $request, RegisterTenant $register, AuditLogger $audit): RedirectResponse
    {
        $admin = $register(
            $request->string('company_name')->toString(),
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        $audit->record(AuditEvent::TenantCreated, $admin->id, ['tenant_id' => $admin->tenant_id]);

        // Registered envía el correo de verificación; sin verificarlo no se pasa de la pantalla de verificación.
        event(new Registered($admin));

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
