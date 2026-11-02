<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Login limits: 5 tries per minute for one account from one IP, and 20 per minute from one IP overall.
        // This stops password guessing. It needs a cache that lasts between requests (file or database, not "array").
        RateLimiter::for('login', function (Request $request) {
            $tooMany = fn ($request, array $headers) => response()->json(
                ['message' => 'Too many login attempts. Please wait a minute and try again.'],
                429,
                $headers
            );

            $login = strtolower(trim((string) $request->input('login')));

            return [
                Limit::perMinute(5)->by($login . '|' . $request->ip())->response($tooMany),
                Limit::perMinute(20)->by($request->ip())->response($tooMany),
            ];
        });

        // The reset link in the mail must open the React page, not a Laravel page.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $url = rtrim((string) (config('app.frontend_url') ?: env('FRONTEND_URL', 'http://localhost:5173')), '/')
                . '/reset-password?token=' . $token
                . '&email=' . urlencode($user->getEmailForPasswordReset());

            // MAIL_MAILER=log writes the mail in a hard to read format, so also write the clean link
            if (app()->isLocal()) {
                Log::info('Password reset link: ' . $url);
            }

            return $url;
        });
    }
}
