<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Sms\LogSmsSender;
use App\Services\Sms\SemaphoreClient;
use App\Services\Sms\SmsSender;
use App\Services\Verification\Channel;
use App\Services\Verification\ChannelRegistry;
use App\Services\Verification\EmailVerificationChannel;
use App\Services\Verification\SmsVerificationChannel;
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

        $this->app->bind(SmsSender::class, function ($app) {
            $usesProvider = config('services.sms.driver') === 'semaphore'
                && filled(config('services.semaphore.key'));

            if (! $usesProvider || $app->runningUnitTests()) {
                return new LogSmsSender();
            }

            return new SemaphoreClient(
                (string) config('services.semaphore.endpoint'),
                (string) config('services.semaphore.key'),
                config('services.semaphore.sender_name') ?: null,
            );
        });

        $this->app->singleton(ChannelRegistry::class, function ($app) {
            return new ChannelRegistry([
                Channel::Sms->value => new SmsVerificationChannel($app->make(SmsSender::class)),
                Channel::Email->value => new EmailVerificationChannel(),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by($this->emailKey($request).'|'.$request->ip()),
                Limit::perMinute(20)->by($request->ip()),
            ];
        });

        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
        RateLimiter::for('user-update', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
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
    /**
     * Per-identifier limiter key, whichever channel the caller is using.
     *
     * Falls back to a shared 'unresolved' bucket when the input is not a valid
     * identifier for any channel, so junk submissions throttle as one group
     * instead of each getting a fresh budget.
     */
    protected function otpIdentifierKey(Request $request): string
    {
        $raw = $request->input('phone_number') ?? $request->input('email');

        if (! is_string($raw) || trim($raw) === '') {
            return 'unresolved';
        }

        $registry = app(ChannelRegistry::class);
        $transport = $registry->forIdentifier($raw);

        if ($transport === null) {
            return 'unresolved';
        }

        return $registry->hash($transport->channel(), $raw) ?? 'unresolved';
    }

    protected function emailKey(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? Str::lower($email) : '';
    }
}
