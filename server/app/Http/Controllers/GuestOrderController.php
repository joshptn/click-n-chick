<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientStock;
use App\Http\Middleware\ResolveGuestOrder;
use App\Http\Resources\OrderTrackingResource;
use App\Models\Cart;
use App\Models\Order;
use App\Services\Orders\CancellationPolicy;
use App\Services\Orders\CheckoutQuote;
use App\Services\Orders\GuestTrackingLink;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderCancellation;
use App\Services\Orders\OrderPlacement;
use App\Services\Orders\OrderStatus;
use App\Services\Payments\CheckoutResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class GuestOrderController extends Controller
{
    private const WITH = ['items.food', 'items.addons', 'user', 'paidPayment'];

    public function __construct(
        private CheckoutQuote $checkout,
        private OrderPlacement $placement,
        private OrderAnnouncer $announcer,
        private OrderCancellation $cancellation,
        private CancellationPolicy $policy,
        private GuestTrackingLink $links,
    ) {}

    public function quote(Request $request)
    {
        return response()->json(
            $this->checkout->build(null, $this->cart($request), $this->validated($request))
        );
    }

    public function store(Request $request)
    {
        $input = $this->validated($request);

        if (($input['fulfilment_type'] ?? null) === null) {
            return response()->json([
                'message' => 'Choose pickup or delivery before placing your order.',
                'error_code' => 'FULFILMENT_TYPE_REQUIRED',
            ], 422);
        }

        $cart = $this->cart($request);
        $quote = $this->checkout->build(null, $cart, $input);

        if (! $quote['can_place']) {
            return response()->json([
                'message' => $quote['blockers'][0]['message'] ?? 'This order can no longer be placed.',
                'error_code' => $quote['blockers'][0]['code'] ?? 'CHECKOUT_BLOCKED',
                'blockers' => $quote['blockers'],
                'quote' => $quote,
            ], 409);
        }

        try {
            // The token's hash is written inside the placement transaction;
            // the token itself is rebuilt here from the order.
            $order = $this->placement->place(null, $cart, $quote);
            $token = $order->guestToken();
        } catch (InsufficientStock $short) {
            return response()->json([
                'message' => $short->getMessage(),
                'error_code' => 'ITEM_STOCK_SHORT',
                'shortages' => $short->shortages,
            ], 409);
        } catch (Throwable $e) {
            Log::error('Failed to place a guest order.', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to place order'], 500);
        }

        // Not announced yet: the shop hears of it when it is paid (PaymentSettlement).
        return app(CheckoutResponder::class)->respond($order, $cart, $quote, [
            // Not stored in plaintext: the browser or app keeps it, and once
            // the order is paid it is rebuilt from the order and emailed
            // (FR-02.13a). It is not given to Xendit: the payment page returns
            // to a fixed address, and the browser takes the guest back from there.
            'tracking_token' => $token,
            'channel' => $order->guestChannel(),
        ]);
    }

    public function show(Request $request)
    {
        $order = $this->order($request);

        // Not an order until it is paid; see OrderTrackingController::show.
        abort_if($order->isUnpaidCheckout(), 404);

        return response()->json([
            'order' => new OrderTrackingResource($order->load(self::WITH)),
            'channel' => $order->guestChannel(),
            // The resend card (FR-02.13b); null once the order has closed.
            'link' => $this->links->status($order),
        ]);
    }

    /**
     * Email the tracking link again, from the card on the tracking page
     * (FR-02.13b, decided 2026-11-22): once per wait, a cap per order, and
     * only while the order is open.
     */
    public function resendLink(Request $request)
    {
        $order = $this->order($request);

        if ($this->links->status($order) === null) {
            return response()->json([
                'message' => 'This order has closed, so its link is no longer sent.',
                'error_code' => 'ORDER_ALREADY_CLOSED',
            ], 422);
        }

        try {
            $outcome = $this->links->resend($order, (string) $request->header(ResolveGuestOrder::HEADER));
        } catch (Throwable $e) {
            Log::warning('A guest tracking link could not be resent.', [
                'order_id' => $order->getKey(),
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "We couldn't send it just now. Please try again in a moment.",
                'error_code' => 'LINK_NOT_SENT',
                'link' => $this->links->status($order),
            ], 503);
        }

        [$status, $body] = match ($outcome) {
            GuestTrackingLink::SENT => [200, [
                'message' => 'Your tracking link has been sent. Please check your email.',
            ]],
            GuestTrackingLink::WAIT => [429, [
                'message' => 'Please wait a moment before sending it again.',
                'error_code' => 'LINK_RESEND_WAIT',
            ]],
            GuestTrackingLink::LIMIT => [429, [
                'message' => "You've reached the resend limit for this order. Keep this page open, or bookmark it, to come back to your order.",
                'error_code' => 'LINK_RESEND_LIMIT',
            ]],
        };

        return response()->json([...$body, 'link' => $this->links->status($order)], $status);
    }

    public function cancel(Request $request)
    {
        $order = $this->order($request);

        $decision = $this->cancellation->cancel($order, null);

        if (! $decision['can_cancel']) {
            return response()->json([
                'message' => $decision['message'],
                'error_code' => $decision['code'],
            ], 400);
        }

        return response()->json([
            'message' => $this->policy->outcomeMessage($decision),
            'refunded' => $decision['refundable'],
            'refund_amount' => $decision['refund_amount'],
            'refund_code' => $decision['code'],
            'order' => new OrderTrackingResource($order->load(self::WITH)),
        ]);
    }

    public function confirmReceipt(Request $request)
    {
        $order = $this->order($request);

        if ($order->order_type !== 'delivery') {
            return response()->json([
                'message' => 'Only a delivery can be confirmed as received.',
                'error_code' => 'NOT_A_DELIVERY',
            ], 422);
        }

        if ($order->status !== OrderStatus::ON_THE_WAY) {
            return response()->json([
                'message' => $order->status === OrderStatus::DELIVERED
                    ? 'This order is already marked delivered.'
                    : 'This order is not on its way yet.',
                'error_code' => 'NOT_ON_THE_WAY',
                'status' => $order->status,
            ], 422);
        }

        $order->status = OrderStatus::DELIVERED;
        $order->save();

        $this->announcer->statusChanged($order);

        return response()->json([
            'message' => 'Thanks for confirming. Enjoy your meal!',
            'order' => new OrderTrackingResource($order->load(self::WITH)),
        ]);
    }

    /** Put there by the guest-order middleware, which every tracking route carries. */
    private function order(Request $request): Order
    {
        return $request->attributes->get(ResolveGuestOrder::ATTRIBUTE);
    }

    private function cart(Request $request): ?Cart
    {
        return Cart::forGuestToken($request->header(CartController::GUEST_HEADER));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'fulfilment_type' => ['nullable', 'in:pickup,delivery'],
            'cart_item_ids' => ['nullable', 'array'],
            'cart_item_ids.*' => ['integer'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'full_address' => ['nullable', 'string', 'max:500'],
            'location' => ['nullable', 'string', 'max:255'],
            'delivery_note' => ['nullable', 'string', 'max:255'],
            'pickup_at' => ['nullable', 'string', 'max:64'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'contact_email' => ['nullable', 'string', 'max:255'],
            'apply_discount' => ['sometimes', 'boolean'],
        ]);
    }
}
