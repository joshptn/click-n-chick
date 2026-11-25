<?php

namespace App\Http\Controllers;

use App\Exceptions\PointsNotEnough;
use App\Http\Middleware\ResolveGuestOrder;
use App\Http\Resources\OrderTrackingResource;
use App\Models\Order;
use App\Services\Loyalty\LoyaltyPoints;
use App\Services\Orders\AdvanceExpiry;
use App\Services\Orders\AdvanceRepricing;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\PriceCheck;
use App\Services\Payments\CheckoutExpiry;
use App\Services\Payments\PaymentCheckout;
use App\Services\Payments\PaymentGatewayException;
use App\Services\Payments\PaymentSettlement;
use App\Support\NativeApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Paying for an order, for its customer or its guest (FR-04.2, FR-04.5).
 *
 *   pay     open the hosted checkout (or go back to the open one)
 *   status  where the payment stands, asked of Xendit when it is still open;
 *           the web app calls it on return from the checkout, so a payment is
 *           seen at once even when the webhook is slow or, locally, absent
 *
 * An advance order is priced again as its payment opens (AdvanceRepricing,
 * decided 2026-11-24). `expected_total`, the total the page showed, stops a
 * payment for a different amount (PriceCheck): the answer is PRICE_CHANGED
 * with the order as it now stands.
 */
class OrderPaymentController extends Controller
{
    private const WITH = ['items.food', 'items.addons', 'user', 'paidPayment'];

    public function __construct(
        private PaymentCheckout $checkout,
        private PaymentSettlement $settlement,
        private CheckoutExpiry $checkoutExpiry,
        private AdvanceExpiry $advanceExpiry,
        private AdvanceRepricing $repricing,
        private OrderAnnouncer $announcer,
        private LoyaltyPoints $points,
    ) {}

    public function pay(Request $request, Order $order)
    {
        $validated = $request->validate([PriceCheck::FIELD => PriceCheck::RULE]);

        return $this->startPayment(
            $this->owned($request, $order),
            NativeApp::isNative($request),
            $request->boolean('reviewed'),
            $validated[PriceCheck::FIELD] ?? null,
        );
    }

    public function status(Request $request, Order $order)
    {
        return $this->report($this->owned($request, $order));
    }

    public function guestPay(Request $request)
    {
        return $this->startPayment($request->attributes->get(ResolveGuestOrder::ATTRIBUTE), NativeApp::isNative($request));
    }

    public function guestStatus(Request $request)
    {
        return $this->report($request->attributes->get(ResolveGuestOrder::ATTRIBUTE));
    }

    /**
     * @param  bool  $reviewed  The customer came through the Review Advance
     *                          Order step (decided 2026-11-20). Its time is
     *                          kept in details_confirmed_at, rewritten on each
     *                          review; nothing else writes that column.
     * @param  mixed  $shown  The total the page showed (PriceCheck), if sent.
     */
    private function startPayment(Order $order, bool $toApp, bool $reviewed = false, mixed $shown = null)
    {
        if ($refusal = $this->enforce($order)) {
            return response()->json($refusal, 422);
        }

        if ($order->isPaid()) {
            return $this->alreadyPaid($order);
        }

        if (! PaymentCheckout::awaitsPayment($order)) {
            return response()->json([
                'message' => 'This order is not waiting for payment.',
                'error_code' => 'NOT_AWAITING_PAYMENT',
                'status' => $order->status,
            ], 422);
        }

        if ($order->isAdvance() && $this->repricing->quote($order) !== null) {
            // A checkout opened at the old amount must not take it any more. If
            // it was paid meanwhile, the price on that page stands (FR-02.6).
            if ($this->checkout->closeOpen($order)) {
                return $this->alreadyPaid($order->refresh());
            }

            if ($this->repricing->apply($order)) {
                $this->announcer->announce($order->refresh(), 'update');
            }
        }

        // An advance order's points are taken as its payment opens, at the
        // day's prices (decided 2026-11-24). If the balance no longer covers
        // them, those dishes are charged instead and the customer is told
        // before paying anything.
        if ($order->isAdvance() && (float) $order->points_amount > 0 && ! $this->points->hold($order)) {
            if ($this->checkout->closeOpen($order)) {
                return $this->alreadyPaid($order->refresh());
            }

            $dish = $order->items()->where('redeemed_quantity', '>', 0)->with('food')->get();
            $this->repricing->dropRedemption($order);
            $this->points->hold($order->refresh());
            $this->announcer->announce($order, 'update');

            return response()->json([
                'message' => sprintf(
                    'Your points no longer cover %s, so %s charged this time. Your total is now ₱%s. Please check it, then try again.',
                    $dish->count() === 1 ? ($dish->first()->food?->food_name ?? 'the dish you picked') : 'the dishes you picked',
                    $dish->count() === 1 ? 'it is' : 'they are',
                    number_format((float) $order->total_amount, 2),
                ),
                'error_code' => PointsNotEnough::CODE,
                'order' => $this->resource($order),
            ], 409);
        }

        if ($refusal = PriceCheck::refusal($shown, (float) ($order->total_amount ?? $order->total_price))) {
            return response()->json($refusal + ['order' => $this->resource($order)], 409);
        }

        if ($reviewed && $order->isAdvance()) {
            $order->forceFill(['details_confirmed_at' => now()])->save();
        }

        // Wholly paid with points: nothing to pay, so no payment page.
        if (round((float) ($order->total_amount ?? $order->total_price), 2) <= 0) {
            $order = $this->settlement->settleWithPoints($order);

            return response()->json([
                'paid' => $order->isPaid(),
                'payment' => null,
                'order' => $this->resource($order),
            ]);
        }

        try {
            $session = $this->checkout->open($order, $toApp);
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

    private function alreadyPaid(Order $order)
    {
        return response()->json([
            'message' => 'This order is already paid.',
            'error_code' => 'ALREADY_PAID',
            'order' => $this->resource($order),
        ], 409);
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
