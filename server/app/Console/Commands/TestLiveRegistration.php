<?php

namespace App\Console\Commands;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\PendingRegistration;
use App\Services\Otp\OtpService;
use App\Services\Verification\Channel;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * The whole registration loop, for real, end to end.
 *
 * Parks a sign-up in the cache the way AuthController::register() does, issues
 * a code through the same OtpService call, sends it over the live mailer,
 * waits for you to type what actually arrived, and then puts that code through
 * the real POST /api/otp/verify route - real middleware, real controller, real
 * gating - which is what creates the account.
 *
 * This is the only check that proves the whole chain rather than its parts:
 * that the email leaves, that the code inside it is the code the database is
 * holding, and that entering it creates the account and issues a token.
 */
class TestLiveRegistration extends Command
{
    protected $signature = 'registration:test-live
                            {email : A mailbox you can actually read}
                            {--keep : Leave the throwaway account behind afterwards}';

    protected $description = 'Register through the live mailer, then verify with the code that arrives';

    public function handle(OtpService $otp, PendingRegistration $pending): int
    {
        $email = User::normalizeEmail((string) $this->argument('email'));
        $mailer = (string) config('mail.default');

        if ($email === null) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        $this->line('  MAIL_MAILER : '.$mailer);
        $this->line('  recipient   : '.$email);
        $this->newLine();

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->error("MAIL_MAILER is '{$mailer}'. Nothing will be delivered, so this proves nothing.");

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("{$email} is already registered. Use a different address, or delete that account first.");

            return self::FAILURE;
        }

        if (! $this->confirm("Send a real sign-up code to {$email} and create a throwaway account from it?", false)) {
            $this->info('Aborted. Nothing was created or sent.');

            return self::SUCCESS;
        }

        // Mirrors AuthController::register for the email channel.
        $pending->put([
            'first_name' => 'Preflight',
            'last_name' => 'Check',
            'identifier' => $email,
            'channel' => Channel::Email->value,
            'password' => Hash::make('Preflight-Password1'),
        ]);

        try {
            $otp->sendTo(Channel::Email, $email, OtpCode::PURPOSE_REGISTRATION);
        } catch (Throwable $e) {
            $this->error('The code was generated but the mailer refused to send it.');
            $this->line($e->getMessage());
            $this->line('Run `php artisan mail:test-send '.$email.'` to diagnose the transport on its own.');
            $pending->forget(Channel::Email, $email);

            return self::FAILURE;
        }

        $this->info('Code issued and handed to the mailer.');
        $this->newLine();
        $this->line("Open {$email} and find the message from Click n Chick.");
        $this->newLine();

        $code = trim((string) $this->ask('Enter the 6-digit code exactly as it arrived'));

        if ($code === '') {
            $this->error('No code entered. Nothing was created.');
            $pending->forget(Channel::Email, $email);

            return self::FAILURE;
        }

        // Through the real route, not the service, so middleware and the
        // channel gating are exercised too.
        $request = Request::create(
            '/api/otp/verify',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            json_encode(['identifier' => $email, 'code' => $code]),
        );

        $response = app()->handle($request);
        $payload = json_decode($response->getContent(), true) ?: [];

        $this->newLine();

        if ($response->getStatusCode() !== 200) {
            $this->error('VERIFICATION REFUSED - HTTP '.$response->getStatusCode());
            $this->line('  message : '.($payload['message'] ?? '(none)'));
            $this->line('  reason  : '.($payload['reason'] ?? '(none)'));
            $this->newLine();
            $this->line('If the code you typed matches the email, the delivery worked and the mismatch is elsewhere.');
            $this->line('If no email arrived at all, the transport is the problem: php artisan mail:test-send '.$email);
            $pending->forget(Channel::Email, $email);

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        $this->info('VERIFICATION ACCEPTED.');
        $this->newLine();
        $this->line('  account created   : '.($user ? '#'.$user->id : 'NO'));
        $this->line('  email_verified_at : '.($user?->email_verified_at ?? '(null)'));
        $this->line('  token issued      : '.(filled($payload['token'] ?? null) ? 'yes' : 'NO'));
        $this->newLine();

        $ok = $user !== null && $user->email_verified_at !== null && filled($payload['token'] ?? null);

        if ($ok) {
            $this->info('The mailer delivered a code that registered a user. The whole chain works.');
        } else {
            $this->error('The request succeeded but the account did not end up registered. Check the values above.');
        }

        if ($user !== null) {
            $this->cleanUp($user);
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function cleanUp(User $user): void
    {
        if ($this->option('keep')) {
            $this->warn("Throwaway account #{$user->id} left in place (--keep).");

            return;
        }

        $user->tokens()->delete();
        OtpCode::where('user_id', $user->id)->delete();
        $user->delete();

        $this->line('Throwaway account removed.');
    }
}
