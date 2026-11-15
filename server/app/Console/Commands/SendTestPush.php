<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Push\PushSender;
use Illuminate\Console\Command;
use Throwable;

/**
 * Manual check of the Firebase setup: sends one push, now (not through the
 * queue), to every device a customer account has registered, and says what
 * happened. Only the push: no in-app notification is written.
 */
class SendTestPush extends Command
{
    protected $signature = 'push:test
                            {user : The customer account, by id or email}
                            {--title=Click n Chick : Notification title}
                            {--body=This is a test notification. : Notification body}';

    protected $description = 'Send one test push to a customer account\'s registered devices';

    public function handle(PushSender $sender): int
    {
        if (! $sender->isConfigured()) {
            $this->error('Firebase is not configured: set FIREBASE_CREDENTIALS in .env.');

            return self::FAILURE;
        }

        $key = (string) $this->argument('user');
        $user = ctype_digit($key)
            ? User::find((int) $key)
            : User::query()->where('email', strtolower($key))->first();

        if ($user === null) {
            $this->error('No account matches that id or email.');

            return self::FAILURE;
        }

        $devices = $user->pushTokens()->count();

        if ($devices === 0) {
            $this->warn('That account has no registered device. Turn notifications on in the app first.');

            return self::FAILURE;
        }

        if ($user->role !== User::ROLE_CUSTOMER) {
            $this->warn('That account is not a customer, so pushes are not sent to it.');

            return self::FAILURE;
        }

        try {
            $result = $sender->toUser((int) $user->getKey(), (string) $this->option('title'), (string) $this->option('body'), [
                'type' => 'test',
            ]);
        } catch (Throwable $e) {
            $this->error('Not sent: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line("Devices: {$devices}");
        $this->info("Delivered to FCM: {$result->sent}");
        $this->line('Removed as no longer valid: '.count($result->invalid));

        foreach (array_unique($result->failed) as $reason) {
            $this->error('Failed: '.$reason);
        }

        return $result->failed === [] ? self::SUCCESS : self::FAILURE;
    }
}
