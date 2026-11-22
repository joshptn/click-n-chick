<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * A guest's tracking link (FR-02.13a, decided 2026-11-22).
 *
 * Sent once when the payment is confirmed (SendGuestTrackingLink), and again
 * whenever the guest asks from the tracking page (GuestTrackingLink::resend).
 * Not a receipt: the order number, the date and the link, nothing more.
 */
class GuestTrackingLinkMail extends Mailable
{
    public function __construct(
        public Order $order,
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        // "Track your order 005 – BES House of Chicken"
        return new Envelope(
            subject: 'Track your '.lcfirst($this->order->title()).' – BES House of Chicken',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guest-tracking-link',
            with: [
                'title' => $this->order->title(),
                'placedOn' => $this->order->created_at
                    ?->copy()
                    ->timezone((string) config('store.timezone'))
                    ->format('F j, Y'),
                'url' => $this->url,
            ],
        );
    }
}
