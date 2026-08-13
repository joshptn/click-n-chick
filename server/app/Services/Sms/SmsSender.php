<?php

namespace App\Services\Sms;

interface SmsSender
{
    /**
     * Deliver a plain-text SMS.
     *
     * @param  string  $to       Canonical '+63XXXXXXXXXX'. Implementations adapt
     *                           this to whatever shape their provider wants.
     * @param  string  $message  Plain text. Must never contain a URL - Smart
     *                           rejects shortened links at the telco level, and
     *                           an OTP has no business carrying one.
     *
     * @throws SmsDeliveryException when the provider did not accept the message.
     */
    public function send(string $to, string $message): void;
}
