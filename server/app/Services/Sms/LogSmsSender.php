<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the log instead of dispatching it.
 *
 * The default driver for local development and the only driver tests ever use.
 * The code is substituted and logged on purpose: it is what makes the flow
 * exercisable end to end without spending credits, and - more importantly here
 * - without hammering a real handset. Semaphore's OTP endpoint is not rate
 * limited on their side, and repeated testing against a live number risks the
 * account.
 */
class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message, ?string $otpCode = null): void
    {
        $rendered = $otpCode === null
            ? $message
            : str_replace('{otp}', $otpCode, $message);

        Log::info('SMS (log driver - not sent)', [
            'to' => $to,
            'message' => $rendered,
        ]);
    }
}
