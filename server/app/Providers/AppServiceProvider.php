<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        $this->configureRateLimiting();
    }

    /**
     * Register the named rate limiters referenced by the api middleware group
     * and by individual routes in routes/api.php.
     */
    protected function configureRateLimiting(): void
    {
        // Baseline for every /api route. Applied via ->throttleApi() in bootstrap/app.php.
        // Authenticated callers are keyed by id so users behind a shared NAT/IP do not
        // consume each other's budget; guests fall back to IP.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Login is the credential-stuffing target. Two limits are returned: a tight one
        // per email+IP pair to stop password guessing against a single account, and a
        // looser per-IP one to stop an attacker spraying many accounts from one host.
        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by($this->emailKey($request).'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        // Account creation is unauthenticated and writes a row, so key on IP only.
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Authenticated profile writes.
        RateLimiter::for('user-update', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Order placement writes an order, uploads proof of payment to Cloudinary and
        // fires an outbound broadcast, so it is the most expensive authenticated write.
        RateLimiter::for('place-order', function (Request $request) {
            return Limit::perMinute(8)->by($request->user()?->id ?: $request->ip());
        });
    }

    /**
     * Normalise the submitted email for use in a rate limiter key.
     *
     * Guards against non-string input (e.g. email[]=foo), which would otherwise
     * raise an "array to string conversion" error inside the limiter callback.
     */
    protected function emailKey(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? Str::lower($email) : '';
    }
}
