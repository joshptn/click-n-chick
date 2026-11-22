<?php

namespace App\Services\Orders;

use App\Mail\GuestTrackingLinkMail;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emailing a guest their tracking link (FR-02.13a, FR-02.13b; decided
 * 2026-11-22).
 *
 *   sendFirst  once, when the payment is confirmed, from the queue
 *              (SendGuestTrackingLink); the token is rebuilt from the order
 *   resend     from the tracking page's card, with the token the guest is
 *              holding, sent at once so the answer is truthful
 *   status     what that card shows: the address, the wait, the sends left
 *
 * Anyone holding a link can change its email address and press Resend, so
 * both are limited: one send per wait (the automatic email starts it too),
 * and a cap per order. A failed send costs nothing. The counts live in the
 * cache, not on the order: they only matter while the link works.
 */
class GuestTrackingLink
{
    public const SENT = 'sent';

    public const WAIT = 'wait';

    public const LIMIT = 'limit';

    /** The automatic email. Throws when the mail could not be sent, so the job can try again. */
    public function sendFirst(Order $order): void
    {
        $token = $order->guestToken();

        if ($token === null) {
            Log::warning('A guest tracking link could not be rebuilt for its email.', ['order_id' => $order->getKey()]);

            return;
        }

        $this->deliver($order, $token);

        Cache::put($this->waitKey($order), $this->waitEndsAt(), $this->wait());
    }

    /**
     * A resend from the tracking page. The token is the one the guest
     * presented, already matched to this order by ResolveGuestOrder, so a
     * link from before tokens were derived can be resent too.
     *
     * @return self::SENT|self::WAIT|self::LIMIT
     *
     * @throws Throwable when the mail could not be sent; nothing is counted then
     */
    public function resend(Order $order, string $token): string
    {
        // add() writes only when nothing is there, so two quick taps cannot
        // both get through.
        if (! Cache::add($this->waitKey($order), $this->waitEndsAt(), $this->wait())) {
            return self::WAIT;
        }

        if ($this->used($order) >= $this->max()) {
            Cache::forget($this->waitKey($order));

            return self::LIMIT;
        }

        try {
            $this->deliver($order, $token);
        } catch (Throwable $e) {
            Cache::forget($this->waitKey($order));

            throw $e;
        }

        Cache::add($this->countKey($order), 0, $this->untilLinkEnds($order));
        Cache::increment($this->countKey($order));

        return self::SENT;
    }

    /**
     * What the tracking page's card needs. Null once the order has closed
     * (the card is shown only while it is open), and for an unpaid checkout,
     * which has no link to send yet.
     *
     * @return array{email: ?string, resend_in: int, resends_left: int}|null
     */
    public function status(Order $order): ?array
    {
        if ($order->isTerminal() || $order->isUnpaidCheckout()) {
            return null;
        }

        $waitEndsAt = (int) Cache::get($this->waitKey($order), 0);

        return [
            'email' => $order->guest_email,
            'resend_in' => max(0, $waitEndsAt - CarbonImmutable::now()->getTimestamp()),
            'resends_left' => max(0, $this->max() - $this->used($order)),
        ];
    }

    /** The web address the email carries. The token rides after "#", so it never reaches the web host. */
    public function url(string $token): string
    {
        return config('app.frontend_url').'/track#'.$token;
    }

    private function deliver(Order $order, string $token): void
    {
        Mail::to($order->guest_email)->send(new GuestTrackingLinkMail($order, $this->url($token)));
    }

    private function used(Order $order): int
    {
        return (int) Cache::get($this->countKey($order), 0);
    }

    private function max(): int
    {
        return max(0, (int) config('store.guest.link_resends_per_order'));
    }

    private function wait(): int
    {
        return max(1, (int) config('store.guest.link_resend_wait_seconds'));
    }

    private function waitEndsAt(): int
    {
        return CarbonImmutable::now()->getTimestamp() + $this->wait();
    }

    /** The count is kept as long as the link works, and no longer. */
    private function untilLinkEnds(Order $order): int
    {
        $ends = $order->guestAccessExpiresAt();

        return $ends === null ? 60 : max(60, $ends->getTimestamp() - CarbonImmutable::now()->getTimestamp());
    }

    private function waitKey(Order $order): string
    {
        return 'guest-link-wait:'.$order->getKey();
    }

    private function countKey(Order $order): string
    {
        return 'guest-link-sends:'.$order->getKey();
    }
}
