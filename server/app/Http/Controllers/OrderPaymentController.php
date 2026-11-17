<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ResolveGuestOrder;
use App\Http\Resources\OrderTrackingResource;
use App\Models\Order;
use App\Services\Orders\AdvanceExpiry;
use App\Services\Payments\CheckoutExpiry;
use App\Services\Payments\PaymentCheckout;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentSettlement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Paying for an order, for its customer or its guest (FR-04.2, FR-04.5).
 *
 *   pay     open the hosted checkout (or go back to the open one)
 *   status  where the payment stands, asked of Xendit when it is still open;
 *           the web app calls it on return from the checkout, so a payment is
 *           seen at once even when the webhook is slow or, locally, absent
 */
class OrderPaymentController extends Controller
{
    private const WITH = ['items.food', 'items.addons', 'user'];

    public function __construct(
        private PaymentCheckout $checkout,
        private PaymentSettlement $settlement,
        private CheckoutExpiry $checkoutExpiry,
        private AdvanceExpiry $advanceExpiry,
    ) {}

    public function pay(Request $request, Order $order)
    {
        return $this->startPayment($this->owned($request, $order));
    }

    public function status(Request $request, Order $order)
    {
        return $this->report($this->owned($request, $order));
    }

    public function guestPay(Request $request)
    {
        return $this->startPayment($request->attributes->get(ResolveGuestOrder::ATTRIBUTE));
    }

    public function guestStatus(Request $request)
    {
        return $this->report($request->attributes->get(ResolveGuestOrder::ATTRIBUTE));
    }

    private function startPayment(Order $order)
    {
        if ($refusal = $this->enforce($order)) {
            return response()->json($refusal, 422);
        }

        if ($order->isPaid()) {
            return response()->json([
                'message' => 'This order is already paid.',
                'error_code' => 'ALREADY_PAID',
                'order' => $this->resource($order),
            ], 409);
        }

        if (! PaymentCheckout::awaitsPayment($order)) {
            return response()->json([
                'message' => 'This order is not waiting for payment.',
                'error_code' => 'NOT_AWAITING_PAYMENT',
                'status' => $order->status,
            ], 422);
        }

        try {
            $session = $this->checkout->open($order);
        } catch (PaymentGatewayException $e) {
            Log::error('A checkout could not be opened with Xendit.', ['order_id' => $order->getKey(), 'error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Payment could not be started just now. Please try again in a moment.',
                'error_code' => 'PAYMENT_UNAVAILABLE',
            ], 502);
        }

        $order->refresh();

        return response()->json([
            'paid' => $session->isCompleted(),
            'payment' => $session->isCompleted() ? null : [
                'url' => $session->url,
                'expires_at' => $session->expiresAt?->toIso8601String(),
            ],
            'order' => $this->resource($order),
        ]);
    }

    private function report(Order $order)
    {
        foreach ($order->payments()->pending()->latest('id')->get() as $payment) {
            try {
                $this->settlement->reconcile($payment);
            } catch (PaymentGatewayException $e) {
                Log::info('A payment could not be checked with Xendit just now.', ['order_id' => $order->getKey(), 'error' => $e->getMessage()]);
            }
        }

        $order->refresh();
        $this->enforce($order);

        return response()->json([
            'paid' => $order->isPaid(),
            'order' => $this->resource($order),
        ]);
    }

    /** @return array<string, string>|null */
    private function enforce(Order $order): ?array
    {
        return $order->isAdvance()
            ? $this->advanceExpiry->enforce($order)
            : $this->checkoutExpiry->enforce($order);
    }

    /** A customer pays for their own orders only; anything else does not exist for them. */
    private function owned(Request $request, Order $order): Order
    {
        abort_unless($order->user_id !== null && (int) $order->user_id === (int) $request->user()->getKey(), 404);

        return $order;
    }

    private function resource(Order $order): OrderTrackingResource
    {
        return new OrderTrackingResource($order->load(self::WITH));
    }
}
