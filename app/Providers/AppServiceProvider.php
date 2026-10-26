<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Password::defaults(fn () => Password::min(8)
            ->letters()
            ->numbers()
            // Verification HIBP (reseau) uniquement en production.
            ->when(app()->isProduction(), fn (Password $rule) => $rule->uncompromised()));

        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        $email = fn (Request $r) => strtolower((string) $r->input('email'));

        // Limite globale de l'API : par utilisateur authentifie, sinon par IP.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        RateLimiter::for('geocoding', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));

        // Anti brute-force : par (e-mail + IP) et par IP seule.
        RateLimiter::for('login', fn (Request $r) => [
            Limit::perMinute(5)->by('login:'.$email($r).'|'.$r->ip()),
            Limit::perMinute(30)->by('login-ip:'.$r->ip()),
        ]);

        RateLimiter::for('register', fn (Request $r) => [
            Limit::perMinute(5)->by('register:'.$r->ip()),
            Limit::perHour(30)->by('register-h:'.$r->ip()),
        ]);

        RateLimiter::for('refresh', fn (Request $r) => Limit::perMinute(30)->by('refresh:'.$r->ip()));

        // 1 envoi de code / minute par e-mail (la limite s'applique qu'il existe ou non => pas d'enumeration).
        RateLimiter::for('forgot-password', fn (Request $r) => [
            Limit::perMinute(1)->by('forgot:'.$email($r)),
            Limit::perHour(10)->by('forgot-ip:'.$r->ip()),
        ]);

        RateLimiter::for('reset-password', fn (Request $r) => [
            Limit::perMinute(5)->by('reset:'.$email($r).'|'.$r->ip()),
            Limit::perMinute(20)->by('reset-ip:'.$r->ip()),
        ]);

        RateLimiter::for('otp-resend', fn (Request $r) => Limit::perMinute(1)->by('otp-resend:'.$r->user()?->id));

        RateLimiter::for('verify-email', fn (Request $r) => Limit::perMinute(5)->by('verify:'.$r->user()?->id));
    }
}
