<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * What a guest is told by email about a cancellation or a refund (decided
 * 2026-11-23: guests have no account, so email is how they hear). Always
 * carries the tracking link when it can be rebuilt.
 *
 * The wording comes from OrderNotice::guestEmail(), the same service that
 * words an account holder's notices.
 */
class GuestOrderNotice extends Mailable
{
    /** Cancelled by the store or by the guest. */
    public const CANCELLED = 'cancelled';

    /** Paid, but the order could not go ahead. */
    public const PAYMENT_CANCELLED = 'payment_cancelled';

    /** Paid twice: the extra payment is owed back. */
    public const EXTRA_PAYMENT = 'extra_payment';

    /** A refund has been sent. */
    public const REFUNDED = 'refunded';

    public const KINDS = [self::CANCELLED, self::PAYMENT_CANCELLED, self::EXTRA_PAYMENT, self::REFUNDED];

    /**
     * @param  array{subject: string, heading: string, body: string}  $notice
     */
    public function __construct(
        public Order $order,
        public array $notice,
        public ?string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->notice['subject'].' – BES House of Chicken');
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guest-order-notice',
            with: [
                'heading' => $this->notice['heading'],
                'body' => $this->notice['body'],
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
