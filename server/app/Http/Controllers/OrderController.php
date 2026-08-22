<?php

namespace App\Http\Controllers;

use App\Events\OrderBroadcast;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Orders\CheckoutQuote;
use App\Services\Store\StoreAvailability;
use App\Utils\Notification;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller implements HasMiddleware
{
    use AuthorizesRequests;

    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum'),
        ];
    }

    /**
     * POST /api/order/place
     *
     * Re-runs the whole Dispatch quote before writing anything. The checkout
     * screen has already been told what this order costs and whether it may be
     * placed, but that was a previous request against a store that may since
     * have closed, a menu that may since have sold out, and a fee that may
     * since have changed. The quote is the authority, here as there - the
     * request body supplies a selection and a destination, never a price.
     */
    public function placeOrder(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            // `type` is the historical name for this field; the checkout
            // screen sends `fulfilment_type`. Both are accepted so an older
            // client is not broken by the rename.
            'type' => ['nullable', 'in:delivery,pickup'],
            'fulfilment_type' => ['nullable', 'in:delivery,pickup'],
            'cart_item_ids' => ['nullable', 'array'],
            'cart_item_ids.*' => ['integer'],
            'address_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:255'],
            'full_address' => ['nullable', 'string', 'max:500'],
            'delivery_note' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'pickup_at' => ['nullable', 'string', 'max:64'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
        ]);

        $validated['fulfilment_type'] = $validated['fulfilment_type'] ?? $validated['type'] ?? null;

        if ($validated['fulfilment_type'] === null) {
            return response()->json([
                'message' => 'Choose pickup or delivery before placing your order.',
                'error_code' => 'FULFILMENT_TYPE_REQUIRED',
            ], 422);
        }

        $quote = app(CheckoutQuote::class)->build($user, $validated);

        // 409, not 422: nothing the customer typed is wrong. The world moved
        // between quoting and placing, and the screen needs to re-render with
        // the fresh quote rather than highlight a field.
        if (! $quote['can_place']) {
            return response()->json([
                'message' => $quote['blockers'][0]['message'] ?? 'This order can no longer be placed.',
                'error_code' => $quote['blockers'][0]['code'] ?? 'CHECKOUT_BLOCKED',
                'blockers' => $quote['blockers'],
                'quote' => $quote,
            ], 409);
        }

        $selectedIds = collect($quote['items'])->pluck('id')->all();
        $isDelivery = $quote['fulfilment_type'] === StoreAvailability::TYPE_DELIVERY;
        $destination = $quote['destination'];

        try {
            $order = DB::transaction(function () use ($user, $quote, $selectedIds, $isDelivery, $destination) {
                $order = Order::create([
                    'user_id' => $user->id,
                    'order_type' => $quote['fulfilment_type'],
                    'address_id' => $isDelivery ? ($destination['address_id'] ?? null) : null,
                    'status' => 'pending',
                    'subtotal' => $quote['subtotal'],
                    'discount_amount' => $quote['discount']['amount'],
                    'delivery_fee' => $quote['delivery_fee'],
                    'delivery_distance_km' => $isDelivery ? ($quote['delivery']['distance_km'] ?? null) : null,
                    'total_amount' => $quote['total'],
                    // Kept in step with total_amount: older screens read this
                    // one, and two columns disagreeing about the price of the
                    // same order is worse than the duplication.
                    'total_price' => $quote['total'],
                    'pickup_at' => $isDelivery ? null : ($quote['pickup']['requested_at'] ?? null),
                    'full_address' => $isDelivery ? ($destination['full_address'] ?? null) : null,
                    'latitude' => $isDelivery ? ($destination['latitude'] ?? null) : null,
                    'longitude' => $isDelivery ? ($destination['longitude'] ?? null) : null,
                    'location' => $isDelivery ? ($destination['locality'] ?? null) : null,
                    'delivery_note' => $isDelivery ? ($destination['delivery_note'] ?? null) : null,
                    // Payment is a separate module. The order is written
                    // unpaid; the payment flow attaches the Payment record and
                    // moves the status on.
                    'payment_status' => 'unpaid',
                ]);

                foreach ($quote['items'] as $line) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'food_id' => $line['food_id'],
                        'quantity' => $line['quantity'],
                        'price' => $line['unit_price'],
                    ]);
                }

                // Only what was checked out. Partial checkout (BR-25) means
                // the rest of the cart must survive.
                CartItem::where('user_id', $user->id)->whereIn('id', $selectedIds)->delete();

                return $order;
            });
        } catch (\Throwable $e) {
            Log::error('Failed to place an order.', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to place order'], 500);
        }

        $this->announce(fn () => [
            OrderBroadcast::dispatch($order->load('items.food', 'items.food.category', 'user'), 'create'),
            Notification::notify('order', 'create', $order->user->id, $order),
        ], $order);

        return response()->json([
            'message' => 'Order Placed',
            'order' => $order,
        ], 201);
    }

    /**
     * Fire the real-time notices for a change that is already saved.
     *
     * Every caller runs this *after* its write has committed, and a failure
     * here is logged rather than raised. Reverb being unreachable is not a
     * reason to tell a customer their order failed - they would place it
     * again - or to tell a Store Agent that a status change they can see in
     * the list did not happen (NFR-03).
     *
     * The notification row is written before its broadcast, so what the
     * socket missed is still waiting on the next fetch.
     */
    private function announce(callable $broadcasts, Order $order): void
    {
        try {
            $broadcasts();
        } catch (\Throwable $e) {
            Log::warning('An order changed but its broadcast failed.', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function getUserOrder(Request $request)
    {
        $user = $request->user();

        $orders = $user->Orders()->with('items.food', 'items.food.category', 'user')->orderBy('created_at', 'desc')->get();

        if ($orders->isEmpty()) {
            return response()->json(['message' => 'No orders found'], 404);
        }

        return response()->json([
            'orders' => $orders,
        ], 200);
    }

    public function cancelOrder(Request $request, $orderId)
    {
        $user = $request->user();
        $order = $user->Orders()->where('id', $orderId)->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json(['message' => 'Order cannot be cancelled'], 400);
        }

        $order->status = 'cancelled';
        $order->save();

        $this->announce(fn () => [
            OrderBroadcast::dispatch($order->load('items'), 'cancelled'),
            Notification::notify('order', 'cancelled', $order->user->id, $order),
        ], $order);

        return response()->json(['message' => 'Order cancelled successfully', 'order' => $order], 200);
    }

    public function updateOrderStatus(Request $request, $orderId)
    {
        $this->authorize('isAdmin', Order::class);

        $request->validate([
            'status' => 'required|in:pending,approved,declined,completed',
        ]);

        $order = Order::find($orderId);

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $order->status = $request->status;
        $order->save();

        $this->announce(fn () => [
            OrderBroadcast::dispatch($order->load('items.food', 'items.food.category'), 'update'),
            Notification::notify('order', $order->status, $order->user->id, $order),
        ], $order);

        return response()->json(['message' => 'Order status updated', 'order' => $order], 200);
    }

    public function updateOrderETC(Request $request, $orderId)
    {
        $this->authorize('isAdmin', Order::class);

        $request->validate([
            'etc' => 'required|numeric',
        ]);

        $order = Order::find($orderId);

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $order->estimated_time_of_completion = $request->etc;
        $order->save();

        $this->announce(fn () => [
            OrderBroadcast::dispatch($order->load('items.food', 'items.food.category'), 'update'),
            Notification::notify('order', 'update', $order->user->id, $order),
        ], $order);

        return response()->json(['message' => 'Order status updated', 'order' => $order], 200);
    }

    public function allOrders(Request $request)
    {
        $this->authorize('isAdmin', Order::class);

        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $perPage = $request->get('per_page', 10);
        $status = $request->get('status');
        $category = $request->get('category');

        $query = Order::with(['items.food.category', 'user']);

        if ($status && $status !== 'all') {
            $query->where('status', $status);
        }

        if ($category && $category !== 'all') {
            $query->whereHas('items.food.category', function ($q) use ($category) {
                $q->where('name', 'LIKE', "%{$category}%");
            });
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'orders' => $orders->items(),
            'current_page' => $orders->currentPage(),
            'last_page' => $orders->lastPage(),
            'total' => $orders->total(),
            'per_page' => $orders->perPage(),
        ]);
    }
}
