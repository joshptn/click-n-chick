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

    protected function emailKey(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? Str::lower($email) : '';
    }
}
