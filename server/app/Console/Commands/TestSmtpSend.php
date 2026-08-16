<?php

namespace App\Console\Commands;

use App\Mail\VerificationCodeMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * One real email through the configured SMTP transport.
 *
 * The test suite runs on MAIL_MAILER=array and proves only that the
 * application asked for a send. This proves the transport: DNS, TLS, and
 * above all authentication, which is where a Gmail setup nearly always fails
 * first because it needs an App Password rather than the account password.
 *
 * Sends the same VerificationCodeMail registration uses, so a success here
 * means registration's email will render and deliver the same way.
 */
class TestSmtpSend extends Command
{
    protected $signature = 'mail:test-send
                            {email : Recipient address}
                            {--code= : Throwaway code to put in the message}';

    protected $description = 'Send one real verification email to prove the SMTP credentials work';

    public function handle(): int
    {
        $recipient = (string) $this->argument('email');
        $code = (string) ($this->option('code') ?: str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT));

        $mailer = (string) config('mail.default');

        $this->line('  MAIL_MAILER      : '.$mailer);
        $this->line('  MAIL_HOST        : '.config('mail.mailers.smtp.host'));
        $this->line('  MAIL_PORT        : '.config('mail.mailers.smtp.port'));
        $this->line('  MAIL_SCHEME      : '.(config('mail.mailers.smtp.scheme') ?: '(unset - STARTTLS on 587)'));
        $this->line('  MAIL_USERNAME    : '.(config('mail.mailers.smtp.username') ?: '(empty)'));
        $this->line('  MAIL_PASSWORD    : '.(config('mail.mailers.smtp.password') ? '(set, '.strlen((string) config('mail.mailers.smtp.password')).' chars)' : '(empty)'));
        $this->line('  MAIL_FROM_ADDRESS: '.config('mail.from.address'));
        $this->line('  recipient        : '.$recipient);
        $this->line('  code             : '.$code);
        $this->newLine();

        if (in_array($mailer, ['log', 'array'], true)) {
            $this->error("MAIL_MAILER is '{$mailer}', so nothing will leave this machine.");
            $this->line('Set MAIL_MAILER=smtp in .env, then run: php artisan config:clear');

            return self::FAILURE;
        }

        if (blank(config('mail.mailers.smtp.username')) || blank(config('mail.mailers.smtp.password'))) {
            $this->error('MAIL_USERNAME or MAIL_PASSWORD is empty. Gmail will refuse the connection.');

            return self::FAILURE;
        }

        if (! $this->confirm("Send a real email to {$recipient}?", false)) {
            $this->info('Aborted. Nothing was sent.');

            return self::SUCCESS;
        }

        try {
            Mail::to($recipient)->send(new VerificationCodeMail($code));
        } catch (TransportExceptionInterface $e) {
            $this->error('SMTP REJECTED the message.');
            $this->line($e->getMessage());
            $this->newLine();
            $this->explainFailure($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Send failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('ACCEPTED - the SMTP server took the message.');
        $this->newLine();
        $this->line("Check {$recipient}. The subject line should begin with {$code}.");
        $this->warn('Acceptance is not the same as delivery: the message may still land in spam or be dropped downstream.');

        return self::SUCCESS;
    }

    /** Turn the common Gmail rejections into the actual fix. */
    private function explainFailure(string $message): void
    {
        if (str_contains($message, '535') || stripos($message, 'Username and Password not accepted') !== false) {
            $this->line('535 means the credentials were refused. For Gmail:');
            $this->line('  - MAIL_PASSWORD must be a 16-character App Password, not the account password.');
            $this->line('  - App Passwords require 2-Step Verification to be on for that Google account.');
            $this->line('  - Remove any spaces Google shows in the App Password.');
            $this->line('  - MAIL_USERNAME must be the full address, including @gmail.com.');

            return;
        }

        if (stripos($message, 'certificate') !== false || stripos($message, 'SSL') !== false) {
            $this->line('A TLS problem. For Gmail use MAIL_PORT=587 with MAIL_SCHEME unset (STARTTLS),');
            $this->line('or MAIL_PORT=465 with MAIL_SCHEME=smtps. Do not mix the two.');

            return;
        }

        if (stripos($message, 'Connection could not be established') !== false || stripos($message, 'timed out') !== false) {
            $this->line('The host was unreachable. Check MAIL_HOST, and whether outbound port 587 is blocked.');
        }
    }
}
