<?php

namespace App\Services\Payments;

use App\Http\Resources\OrderTrackingResource;
use App\Models\Cart;
use App\Models\Order;
use App\Services\Orders\OrderPlacement;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * The end of checkout, shared by accounts and guests: open the hosted payment
 * page for the order just created and hand its address to the web app.
 *
 * Only once the payment page exists do the ordered lines leave the cart. If
 * Xendit cannot open one, the order is withdrawn and the cart left as it was.
 */
class CheckoutResponder
{
    public function __construct(
        private PaymentCheckout $checkout,
        private CheckoutExpiry $expiry,
        private OrderPlacement $placement,
    ) {}

    public function respond(Order $order, Cart $cart, array $quote, array $extra = []): JsonResponse
    {
        try {
            $session = $this->checkout->open($order);
        } catch (PaymentGatewayException $e) {
            Log::error('A checkout could not be opened with Xendit.', ['order_id' => $order->getKey(), 'error' => $e->getMessage()]);

            $this->expiry->abandon($order);

            return response()->json([
                'message' => 'Payment could not be started just now. Nothing was charged and your cart is unchanged. Please try again in a moment.',
                'error_code' => 'PAYMENT_UNAVAILABLE',
            ], 502);
        }

        $this->placement->clearCart($cart, $quote);

        return response()->json([
            'message' => 'Checkout opened. Complete the payment to place your order.',
            'order' => new OrderTrackingResource($order->refresh()->load(['items.food', 'items.addons', 'user'])),
            'payment' => [
                'url' => $session->url,
                'expires_at' => $session->expiresAt?->toIso8601String(),
                'due_at' => $order->paymentDueAt()?->toIso8601String(),
            ],
            ...$extra,
        ], 201);
    }
}
