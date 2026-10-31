<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ConfirmTwoFactor;
use App\Actions\Fortify\DisableTwoFactor;
use App\Actions\Fortify\EnableTwoFactor;
use App\Actions\Fortify\GenerateRecoveryCodes;
use App\Actions\Fortify\ResetUserPassword;
use App\Auth\LoginThrottle;
use App\Auth\TwoFactorProvider;
use App\Http\Controllers\Auth\RecoveryCodeController;
use App\Http\Requests\TwoFactorChallengeRequest;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider as TwoFactorAuthenticationProviderContract;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Controllers\RecoveryCodeController as FortifyRecoveryCodeController;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;
use Laravel\Fortify\LoginRateLimiter;
use PragmaRX\Google2FA\Google2FA;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LoginRateLimiter::class, LoginThrottle::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);

        // 2FA: el secreto y los códigos de recuperación se tratan a nuestra manera (ver app/Actions/Fortify).
        $this->app->bind(EnableTwoFactorAuthentication::class, EnableTwoFactor::class);
        $this->app->bind(ConfirmTwoFactorAuthentication::class, ConfirmTwoFactor::class);
        $this->app->bind(DisableTwoFactorAuthentication::class, DisableTwoFactor::class);
        $this->app->bind(GenerateNewRecoveryCodes::class, GenerateRecoveryCodes::class);
        $this->app->bind(TwoFactorLoginRequest::class, TwoFactorChallengeRequest::class);
        $this->app->bind(FortifyRecoveryCodeController::class, RecoveryCodeController::class);
        $this->app->singleton(TwoFactorAuthenticationProviderContract::class, fn (Application $app): TwoFactorProvider => new TwoFactorProvider(
            $app->make(Google2FA::class),
            $app->make(CacheRepository::class),
        ));
    }

    public function boot(): void
    {
        Fortify::loginView(fn () => Inertia::render('auth/Login', ['copy' => __('auth.login')]));
        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('auth/ForgotPassword', ['copy' => __('auth.forgot')]));
        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'copy' => __('auth.reset'),
            'token' => (string) $request->route('token'),
            'email' => $request->string('email')->toString(),
        ]));
        Fortify::verifyEmailView(fn () => Inertia::render('auth/VerifyEmail', ['copy' => __('auth.verify')]));
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge', ['copy' => __('auth.challenge')]));
        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword', ['copy' => __('auth.confirm')]));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = Str::lower($request->string('email')->toString());
            $password = $request->string('password')->toString();

            $user = User::query()->where('email', $email)->first();

            if ($user === null) {
                // Mismo coste de cálculo exista o no la cuenta, para que el tiempo de respuesta no la delate.
                Hash::check($password, $this->dummyHash());

                return null;
            }

            if (! Hash::check($password, $user->password)) {
                return null;
            }

            if (Hash::needsRehash($user->password)) {
                $user->forceFill(['password' => $password])->save();
            }

            return $user;
        });

        // Tope por IP para quien prueba muchos correos distintos; el bloqueo por correo está en LoginThrottle.
        // Tres intentos de código en diez minutos por cuenta, cuenten o no como acierto; el límite sigue al usuario
        // y no a la sesión, así que volver a iniciar sesión no lo reinicia.
        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinutes(10, 3)
            ->by('two-factor:'.$this->challengeSubject($request))
            ->response(function (Request $request, array $headers) {
                $seconds = is_numeric($headers['Retry-After'] ?? null) ? (int) $headers['Retry-After'] : 600;

                return back()->withErrors(['code' => trans('auth.throttle', ['minutes' => max(1, (int) ceil($seconds / 60))])]);
            }));

        // Cinco cambios de contraseña por cuenta cada diez minutos: sin esto, una sesión robada serviría para
        // adivinar la contraseña actual.
        RateLimiter::for('password-change', fn (Request $request): Limit => Limit::perMinutes(10, 5)
            ->by('password-change:'.$this->accountSubject($request))
            ->response(function (Request $request, array $headers) {
                $seconds = is_numeric($headers['Retry-After'] ?? null) ? (int) $headers['Retry-After'] : 600;

                return back()->withErrors(['current_password' => trans('auth.throttle', ['minutes' => max(1, (int) ceil($seconds / 60))])]);
            }));

        RateLimiter::for('login-ip', fn (Request $request): Limit => Limit::perMinute(30)->by((string) $request->ip()));
    }

    private function challengeSubject(Request $request): string
    {
        $userId = $request->session()->get('login.id');

        return is_scalar($userId) ? (string) $userId : (string) $request->ip();
    }

    private function accountSubject(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier();

        return is_scalar($userId) ? (string) $userId : (string) $request->ip();
    }

    private function dummyHash(): string
    {
        return Cache::rememberForever('auth.dummy-password-hash', fn (): string => Hash::make(Str::random(40)));
    }
}
