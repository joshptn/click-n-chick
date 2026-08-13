<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the log instead of dispatching it.
 *
 * This is the active driver for local development and tests, and it is also
 * what the app falls back to while the PhilSMS transport is unverified. The
 * OTP itself is logged so the flow can be exercised end to end without
 * spending trial credits or texting a real handset.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::info('SMS (log driver)', [
            'to' => $to,
            'message' => $message,
        ]);
    }
}
