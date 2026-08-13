<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SemaphoreClient;
use App\Services\Sms\SmsSender;
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
        // SMS_DRIVER defaults to 'log', so normal development and every test run
        // write the code to the log rather than texting a handset. Semaphore's OTP
        // endpoint is NOT rate limited on their side, and repeated live testing
        // risks the account, so reaching the real provider has to be deliberate.
        $this->app->bind(SmsSender::class, function ($app) {
            $usesProvider = config('services.sms.driver') === 'semaphore'
                && filled(config('services.semaphore.key'));

            // A test run must never reach the provider, whatever the env says.
            if (! $usesProvider || $app->runningUnitTests()) {
                return new LogSmsSender();
            }

            return new SemaphoreClient(
                (string) config('services.semaphore.endpoint'),
                (string) config('services.semaphore.key'),
                config('services.semaphore.sender_name') ?: null,
            );
        });
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

        // Sending an OTP costs real money and texts a handset that may not belong to
        // whoever submitted the form, so the phone and the IP are limited separately
        // and both must pass. These are the ONLY limits in play: Semaphore's OTP
        // endpoint is documented as not rate limited, so nothing upstream backs
        // this up if it is wrong. Treat as load-bearing, not best-effort.
        RateLimiter::for('otp-send', function (Request $request) {
            $phoneKey = User::hashPhoneNumber($request->input('phone_number')) ?? 'unresolved';

            return [
                Limit::perMinutes(15, 3)->by('otp-send:phone:'.$phoneKey),
                Limit::perMinutes(1440, 10)->by('otp-send:phone-day:'.$phoneKey),
                Limit::perMinutes(15, 5)->by('otp-send:ip:'.$request->ip()),
                Limit::perMinutes(1440, 30)->by('otp-send:ip-day:'.$request->ip()),
            ];
        });

        // Verification is the brute-force surface. Per-code attempts are capped in
        // OtpService as well; this stops an attacker cycling fresh codes instead.
        RateLimiter::for('otp-verify', function (Request $request) {
            $phoneKey = User::hashPhoneNumber($request->input('phone_number')) ?? 'unresolved';

            return [
                Limit::perMinutes(15, 10)->by('otp-verify:phone:'.$phoneKey),
                Limit::perMinutes(15, 20)->by('otp-verify:ip:'.$request->ip()),
            ];
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
