<?php

namespace App\Console\Commands;

use App\Services\Otp\OtpService;
use App\Services\Sms\SemaphoreClient;
use App\Services\Sms\SmsDeliveryException;
use Illuminate\Console\Command;

/**
 * Deliberate, manual, one-off live send through Semaphore.
 *
 * This is the ONLY path that should ever reach the real provider. Normal
 * development and every test run go through LogSmsSender via SMS_DRIVER=log;
 * this command bypasses that switch on purpose so a live check never depends on
 * leaving the real driver armed in .env.
 *
 * Semaphore's OTP endpoint is not rate limited on their side and credits are
 * finite (2 per 160-character segment), so run this sparingly.
 */
class SendTestOtp extends Command
{
    protected $signature = 'otp:test-send
                            {phone : Recipient, any PH format (09.., 9.., +639.., 639..)}
                            {--code=123456 : Throwaway code to send}
                            {--format= : Override the wire format instead of normalising}
                            {--sender= : Send an explicit sendername instead of omitting it}';

    protected $description = 'Send one real OTP through Semaphore to verify the integration';

    public function handle(): int
    {
        $key = (string) config('services.semaphore.key');

        if ($key === '') {
            $this->error('SEMAPHORE_API_KEY is not set in .env.');

            return self::FAILURE;
        }

        $phone = (string) $this->argument('phone');
        $code = (string) $this->option('code');

        $this->warn('This sends a REAL SMS and spends credits.');
        $this->line('  recipient (as given) : '.$phone);
        $this->line('  code                 : '.$code);
        $this->line('  message length       : '.OtpService::renderedMessageLength().' chars');

        if (! $this->confirm('Proceed?', false)) {
            $this->info('Aborted. Nothing was sent.');

            return self::SUCCESS;
        }

        $senderName = $this->option('sender') ?: (config('services.semaphore.sender_name') ?: null);

        $this->line('  sendername           : '.($senderName ?? '(omitted - account default)'));

        $client = new SemaphoreClient(
            (string) config('services.semaphore.endpoint'),
            $key,
            $senderName,
        );

        $exit = self::SUCCESS;

        try {
            $client->send($this->option('format') ?: $phone, OtpService::messageTemplate(), $code);
            $this->info('ACCEPTED - Semaphore took the message.');
        } catch (SmsDeliveryException $e) {
            $this->error('REJECTED - '.$e->getMessage());
            $exit = self::FAILURE;
        }

        // Full body, for this deliberate verification run only. The client's own
        // logging stays on its allowlist.
        $this->newLine();
        $this->line('--- raw response body ---');
        $this->line(json_encode($client->lastResponse(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $exit;
    }
}
