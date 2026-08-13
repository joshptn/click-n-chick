<?php

namespace App\Services\Sms;

interface SmsSender
{
    /**
     * Deliver a plain-text SMS.
     *
     * @param  string  $to        Canonical '+63XXXXXXXXXX'. Implementations adapt
     *                            this to whatever shape their provider wants.
     * @param  string  $message   Plain text, and never a URL - Smart rejects
     *                            shortened links at the telco level. Where a code
     *                            belongs, the text carries the literal placeholder
     *                            '{otp}' rather than the code itself.
     * @param  string|null  $otpCode  The code to substitute for '{otp}'.
     *
     * Split deliberately: Semaphore's OTP endpoint takes the code as its own
     * `code` parameter and performs the substitution itself. Passing an
     * already-interpolated message would make it append a *second*, unrelated
     * code of its own. The application stays the sole generator - the provider
     * is transport only - and this method still returns void.
     *
     * @throws SmsDeliveryException when the provider did not accept the message.
     */
    public function send(string $to, string $message, ?string $otpCode = null): void;
}
