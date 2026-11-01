<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
    }
}
