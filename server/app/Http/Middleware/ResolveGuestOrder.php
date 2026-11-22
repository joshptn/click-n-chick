<?php

namespace App\Http\Middleware;

use App\Models\Order;
use App\Services\Orders\OrderStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns a tracking token into the order it names.
 *
 * This is what stands in for auth:sanctum on the guest tracking routes. Every
 * route in that group carries it unconditionally, the same way every route in the
 * customer group carries the guard - so reading routes/api.php is enough to see
 * that no path to an order exists without one or the other.
 *
 * Possession IS the authorization here. There is no identity to authorize against,
 * which is why the order arrives as a request attribute rather than through a
 * policy: a policy call would have nothing to decide.
 */
class ResolveGuestOrder
{
    public const HEADER = 'X-Order-Token';

    /**
     * An attribute rather than an input, for the reason VerifyRecaptcha gives:
     * inputs come from the client, and a caller must not be able to nominate the
     * order a request has already been authorized for.
     */
    public const ATTRIBUTE = 'guest.order';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->remove(self::ATTRIBUTE);

        $order = Order::forGuestToken($request->header(self::HEADER));

        // 404, not 403: the route never accepts an order id, so there is nothing
        // here that could confirm an order exists to someone guessing.
        if ($order === null) {
            return $this->notFound();
        }

        /**
         * 410 rather than 404, and safe to distinguish: presenting a hash that
         * matches proves the holder legitimately had this link, because nobody
         * can produce one without having held the token. So saying how the
         * order ended leaks nothing, and it is a far better answer than
         * pretending the order never existed (FR-02.13, decided 2026-11-22).
         */
        if (! $order->guestAccessIsLive()) {
            // Never paid, so never an order: it stays "not found", as it
            // was while the link worked.
            if ($order->isUnpaidCheckout()) {
                return $this->notFound();
            }

            [$code, $message] = match (true) {
                in_array($order->status, [OrderStatus::COMPLETED, OrderStatus::DELIVERED], true) => [
                    'ORDER_COMPLETED',
                    'The order is completed, nothing to track here.',
                ],
                $order->status === OrderStatus::CANCELLED => [
                    'ORDER_CANCELLED',
                    'This order was cancelled. Nothing to track here.',
                ],
                default => [
                    'TRACKING_LINK_EXPIRED',
                    'This tracking link has expired. Please contact the store.',
                ],
            };

            return response()->json(['message' => $message, 'error_code' => $code], 410);
        }

        $request->attributes->set(self::ATTRIBUTE, $order);

        return $next($request);
    }

    private function notFound(): Response
    {
        return response()->json([
            'message' => 'We could not find that order. The link may be wrong, or it may belong to someone else.',
            'error_code' => 'ORDER_NOT_FOUND',
        ], 404);
    }
}
