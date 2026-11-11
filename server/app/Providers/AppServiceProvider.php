<?php

namespace App\Providers;

use App\Services\Geo\OpenRouteServiceClient;
use App\Services\Geo\RoutingProvider;
use App\Services\Mail\GmailOAuth;
use App\Services\Mail\GmailTransport;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SemaphoreClient;
use App\Services\Sms\SmsSender;
use App\Services\Verification\Channel;
use App\Services\Verification\ChannelRegistry;
use App\Services\Verification\EmailVerificationChannel;
use App\Services\Verification\SmsVerificationChannel;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {

        $this->app->bind(SmsSender::class, function ($app) {
            $usesProvider = config('services.sms.driver') === 'semaphore'
                && filled(config('services.semaphore.key'));

            if (! $usesProvider || $app->runningUnitTests()) {
                return new LogSmsSender;
            }

            return new SemaphoreClient(
                (string) config('services.semaphore.endpoint'),
                (string) config('services.semaphore.key'),
                config('services.semaphore.sender_name') ?: null,
            );
        });

        $this->app->bind(RoutingProvider::class, function () {
            return new OpenRouteServiceClient(
                (string) config('services.routing.openrouteservice.endpoint'),
                config('services.routing.openrouteservice.api_key'),
                (string) config('services.routing.openrouteservice.profile'),
            );
        });

        $this->app->singleton(ChannelRegistry::class, function ($app) {
            return new ChannelRegistry([
                Channel::Sms->value => new SmsVerificationChannel($app->make(SmsSender::class)),
                Channel::Email->value => new EmailVerificationChannel,
            ]);
        });
    }

    public function boot(): void
    {
        $this->configureRateLimiting();

        Mail::extend('gmail', fn () => new GmailTransport(
            GmailOAuth::fromConfig(),
            (string) config('services.gmail.endpoint'),
            (int) config('services.gmail.timeout', 10),
        ));
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by('account:'.$this->loginKey($request).'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
        RateLimiter::for('user-update', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('device-trust', function (Request $request) {
            return Limit::perMinutes(15, 5)->by('device-trust:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('two-factor-disable', function (Request $request) {
            return Limit::perMinutes(15, 5)->by('2fa-disable:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('otp-send', function (Request $request) {
            $identifierKey = $this->otpIdentifierKey($request);

            return [
                Limit::perMinutes(15, 3)->by('otp-send:id:'.$identifierKey),
                Limit::perMinutes(1440, 10)->by('otp-send:id-day:'.$identifierKey),
                Limit::perMinutes(15, 5)->by('otp-send:ip:'.$request->ip()),
                Limit::perMinutes(1440, 30)->by('otp-send:ip-day:'.$request->ip()),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            $identifierKey = $this->otpIdentifierKey($request);

            return [
                Limit::perMinutes(15, 10)->by('otp-verify:id:'.$identifierKey),
                Limit::perMinutes(15, 20)->by('otp-verify:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('password-reset', function (Request $request) {
            $identifierKey = $this->otpIdentifierKey($request);

            return [
                Limit::perMinutes(15, 3)->by('pwreset:id:'.$identifierKey),
                Limit::perMinutes(1440, 8)->by('pwreset:id-day:'.$identifierKey),
                Limit::perMinutes(15, 5)->by('pwreset:ip:'.$request->ip()),
                Limit::perMinutes(1440, 20)->by('pwreset:ip-day:'.$request->ip()),
            ];
        });

        RateLimiter::for('discount-apply', function (Request $request) {
            return Limit::perMinutes(60, 5)->by('discount-apply:'.($request->user()?->id ?: $request->ip()));
        });

        RateLimiter::for('place-order', function (Request $request) {
            return Limit::perMinute(8)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('checkout-quote', function (Request $request) {
            return Limit::perMinute(90)->by($request->user()?->id ?: $request->ip());
        });

        // Tighter than the signed-in equivalents, and with a shared daily ceiling
        // on top: a per-IP limit alone cannot bound what a crowd of IPs spends on
        // a third party's free tier. See config/store.php for why the quote
        // ceiling stands in for the routing budget.
        RateLimiter::for('guest-quote', function (Request $request) {
            return [
                Limit::perMinute((int) config('store.guest.quote_per_minute'))->by($request->ip()),
                Limit::perDay((int) config('store.guest.quote_per_day'))->by('guest-quote'),
            ];
        });

        RateLimiter::for('guest-geocode', function (Request $request) {
            return [
                Limit::perMinute((int) config('store.guest.geocode_per_minute'))->by($request->ip()),
                Limit::perDay((int) config('store.guest.geocode_per_day'))->by('guest-geocode'),
            ];
        });

        RateLimiter::for('geocode', function (Request $request) {
            return [
                Limit::perMinute(20)->by('geocode:user:'.($request->user()?->id ?: $request->ip())),
                Limit::perMinute(40)->by('geocode:ip:'.$request->ip()),
            ];
        });
    }

    protected function otpIdentifierKey(Request $request): string
    {
        $raw = $request->input('phone_number')
            ?? $request->input('email')
            ?? $request->input('identifier');

        if (! is_string($raw) || trim($raw) === '') {
            return $request->user()
                ? 'user:'.$request->user()->getKey()
                : 'unresolved';
        }

        $registry = app(ChannelRegistry::class);
        $transport = $registry->forIdentifier($raw);

        if ($transport === null) {
            return 'unresolved';
        }

        return $registry->hash($transport->channel(), $raw) ?? 'unresolved';
    }

    /**
     * The account a sign-in is aimed at, so its attempts are limited on their own.
     *
     * The app signs in with `login` (an email or a phone number); `email` is the
     * older field. Reading only `email` once left every app sign-in sharing one
     * key per IP, so five people on the same network locked each other out.
     * A phone number is reduced to its digits, so spacing and dashes cannot be
     * used to spread guesses at one account across several limits.
     */
    protected function loginKey(Request $request): string
    {
        $identifier = $request->input('login', $request->input('email'));

        if (! is_string($identifier) || trim($identifier) === '') {
            return '';
        }

        $identifier = Str::lower(trim($identifier));

        return str_contains($identifier, '@') ? $identifier : preg_replace('/\D/', '', $identifier);
    }
}
