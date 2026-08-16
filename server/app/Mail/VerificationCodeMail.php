<?php

namespace App\Mail;

use App\Services\Otp\OtpService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerificationCodeMail extends Mailable
{
    /**
     * Not queued: registration blocks on this code arriving, and no queue
     * worker runs for this app yet. Deliberately holds the code in a public
     * property only for the render - nothing logs this object.
     */
    public function __construct(public string $code)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->code.' is your Click n Chick verification code',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.verification-code',
            with: [
                'code' => $this->code,
                'expiryMinutes' => OtpService::EXPIRY_MINUTES,
            ],
        );
    }
}
