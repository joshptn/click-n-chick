<?php

namespace App\Jobs;

use App\Events\NotificationBroadcast;
use App\Mail\NewDeviceAlertMail;
use App\Models\KnownDevice;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Tell the account holder about a sign-in, or a session, from a device the
 * account has not seen (FR-01.11).
 *
 * Runs on the queue worker, so the sign-in answers without waiting for the
 * notification row, the Reverb publish and the email. Tried once: a second
 * delivery of the same alert is noise, and the sign-in it describes has
 * already happened either way.
 */
class SendNewDeviceAlert implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $userId,
        public int $deviceId,
        public ?string $ip,
        public string $context = NewDeviceAlertMail::CONTEXT_NEW_DEVICE,
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        $device = KnownDevice::find($this->deviceId);

        if ($user === null || $device === null) {
            return;
        }

        $moved = $this->context === NewDeviceAlertMail::CONTEXT_SESSION_MOVED;

        try {
            $notification = Notification::create([
                'user_id' => $user->getKey(),
                'title' => $moved ? 'Session used from a new device' : 'New sign-in detected',
                'body' => $moved
                    ? sprintf(
                        'A sign-in created on %s was just used from somewhere else (%s). If this was not you, sign that device out and change your password.',
                        $device->device_name ?? 'another device',
                        $this->ip ?? 'unknown IP'
                    )
                    : sprintf(
                        'Your account was just signed in to from %s (%s). If this was not you, sign that device out and change your password.',
                        $device->device_name ?? 'an unrecognised device',
                        $device->last_ip_address ?? 'unknown IP'
                    ),
                'is_read' => false,
            ]);

            NotificationBroadcast::dispatch($notification, (int) $user->getKey());
        } catch (Throwable $e) {
            Log::warning('Could not raise a new-device notification.', ['error' => $e->getMessage()]);
        }

        try {
            if (filled($user->email)) {
                Mail::to($user->email)->send(new NewDeviceAlertMail($device, $user->first_name, $this->context));
            }
        } catch (Throwable $e) {
            Log::warning('Could not email a new-device alert.', ['error' => $e->getMessage()]);
        }
    }
}
