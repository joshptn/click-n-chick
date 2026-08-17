<?php

namespace App\Mail;

use App\Models\KnownDevice;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Security alert for a sign-in from a device the account has not seen (FR-01.11).
 *
 * Not queued, matching VerificationCodeMail: no queue worker is guaranteed to
 * be running, and a security alert that sits undelivered in a jobs table is
 * worse than one sent inline. The caller sends it inside a try/catch so a mail
 * outage can never break a login.
 */
class NewDeviceAlertMail extends Mailable
{
    public function __construct(
        public KnownDevice $device,
        public ?string $firstName = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New sign-in to your Click n Chick account',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-device-alert',
            with: [
                'deviceName' => $this->device->device_name ?? 'an unrecognised device',
                'ipAddress' => $this->device->last_ip_address ?? 'an unknown address',
                'seenAt' => optional($this->device->last_seen_at)->format('j M Y, g:i a') ?? 'just now',
                'firstName' => $this->firstName,
            ],
        );
    }
}
