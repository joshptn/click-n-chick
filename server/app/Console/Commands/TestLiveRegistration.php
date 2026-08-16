<?php

namespace App\Console\Commands;

use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\OtpService;
use App\Services\Verification\Channel;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * The whole registration loop, for real, end to end.
 *
 * Creates a pending account, issues a code through the same OtpService call
 * AuthController::register() makes, sends it over live SMTP, waits for you to
 * type what actually arrived, and then puts that code through the real
 * POST /api/otp/verify route - real middleware, real controller, real gating.
 *
 * This is the only check that proves the whole chain rather than its parts:
 * that the email leaves, that the code inside it is the code the database is
 * holding, and that entering it activates the account and issues a token.
 */
class TestLiveRegistration extends Command
{
    protected $signature = 'registration:test-live
                            {email : A mailbox you can actually read}
                            {--keep : Leave the throwaway account behind afterwards}';

    protected $description = 'Register through live SMTP, then verify with the code that arrives';

    public function handle(OtpService $otp): int
    {
        $email = (string) $this->argument('email');
        $mailer = (string) config('mail.default');

        $this->line('  MAIL_MAILER : '.$mailer);
        $this->line('  MAIL_HOST   : '.config('mail.mailers.smtp.host'));
        $this->line('  recipient   : '.$email);
        $this->newLine();

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->error("MAIL_MAILER is '{$mailer}'. Nothing will be delivered, so this proves nothing.");
            $this->line('Set MAIL_MAILER=smtp in .env, then run: php artisan config:clear');

            return self::FAILURE;
        }

        if (User::where('email', $email)->whereNotNull('email_verified_at')->exists()) {
            $this->error("{$email} is already registered and verified. Use a different address, or delete that account first.");

            return self::FAILURE;
        }

        if (! $this->confirm("Create a throwaway account for {$email} and send it a real code?", false)) {
            $this->info('Aborted. Nothing was created or sent.');

            return self::SUCCESS;
        }

        // Mirrors AuthController::fillRegistration for the email channel.
        $phone = '+639'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'first_name' => 'Preflight',
                'last_name' => 'Check',
                'password' => Hash::make('Preflight-Password1'),
                'phone_number' => $phone,
                'phone_number_hash' => User::hashPhoneNumber($phone),
                'verification_channel' => Channel::Email->value,
                'account_status' => User::STATUS_PENDING_VERIFICATION,
                'phone_verified_at' => null,
                'email_verified_at' => null,
            ],
        );

        $this->info("Created pending account #{$user->id}.");

        try {
            $otp->send($user, OtpCode::PURPOSE_REGISTRATION, null, Channel::Email);
        } catch (Throwable $e) {
            $this->error('The code was generated but SMTP refused to send it.');
            $this->line($e->getMessage());
            $this->line('Run `php artisan mail:test-send '.$email.'` to diagnose the transport on its own.');
            $this->cleanUp($user);

            return self::FAILURE;
        }

        $this->info('Code issued and handed to SMTP.');
        $this->newLine();
        $this->line("Open {$email} and find the message from Click n Chick.");
        $this->newLine();

        $code = trim((string) $this->ask('Enter the 6-digit code exactly as it arrived'));

        if ($code === '') {
            $this->error('No code entered. The account is left pending.');
            $this->cleanUp($user);

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
            json_encode(['email' => $email, 'code' => $code]),
        );

        $response = app()->handle($request);
        $payload = json_decode($response->getContent(), true) ?: [];

        $fresh = $user->fresh();

        $this->newLine();

        if ($response->getStatusCode() !== 200) {
            $this->error('VERIFICATION REFUSED - HTTP '.$response->getStatusCode());
            $this->line('  message : '.($payload['message'] ?? '(none)'));
            $this->line('  reason  : '.($payload['reason'] ?? '(none)'));
            $this->newLine();
            $this->line('If the code you typed matches the email, the delivery worked and the mismatch is elsewhere.');
            $this->line('If no email arrived at all, the transport is the problem: php artisan mail:test-send '.$email);
            $this->cleanUp($user);

            return self::FAILURE;
        }

        $this->info('VERIFICATION ACCEPTED.');
        $this->newLine();
        $this->line('  email_verified_at : '.($fresh->email_verified_at ?? '(null)'));
        $this->line('  account_status    : '.$fresh->account_status);
        $this->line('  token issued      : '.(filled($payload['token'] ?? null) ? 'yes' : 'NO'));
        $this->newLine();

        $ok = $fresh->email_verified_at !== null
            && $fresh->account_status === User::STATUS_ACTIVE
            && filled($payload['token'] ?? null);

        if ($ok) {
            $this->info('SMTP delivered a code that registered a user. The whole chain works.');
        } else {
            $this->error('The request succeeded but the account did not end up fully registered. Check the three values above.');
        }

        $this->cleanUp($user);

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
