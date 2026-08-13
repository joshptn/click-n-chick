<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\PhilSmsClient;
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
        // PhilSMS stays behind an explicit switch. As of 2026-08-13 the account has
        // no authorized sender ID - neither an alphanumeric brand ID (approval
        // excludes academic use) nor the owner's own number - so every send is
        // rejected by the provider. Enabling it before a sender ID is approved
        // would turn registration into a 500.
        //
        // Flip PHILSMS_ENABLED=true once the dashboard approves a sender ID; the
        // response shape is already confirmed and PhilSmsClient is ready.
        $this->app->bind(SmsSender::class, function ($app) {
            $enabled = (bool) config('services.philsms.enabled')
                && filled(config('services.philsms.token'))
                && filled(config('services.philsms.sender_id'));

            // Never let a test suite reach the real provider.
            if (! $enabled || $app->runningUnitTests()) {
                return new LogSmsSender();
            }

            return new PhilSmsClient(
                (string) config('services.philsms.endpoint'),
                (string) config('services.philsms.token'),
                (string) config('services.philsms.sender_id'),
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
        // and both must pass. The per-phone limit also protects the recipient: PhilSMS
        // warns that repeated near-identical messages can get a number temporarily
        // blocked from receiving SMS altogether.
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
