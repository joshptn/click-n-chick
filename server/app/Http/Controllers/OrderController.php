<?php

namespace App\Http\Controllers;

use App\Exceptions\DiscountAlreadyUsed;
use App\Http\Resources\OrderTrackingResource;
use App\Models\CartItem;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\User;
use App\Services\Orders\CheckoutQuote;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class OrderController extends Controller implements HasMiddleware
{
    use AuthorizesRequests;

    public function __construct(private OrderAnnouncer $announcer) {}

    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum'),
        ];
    }

    public function placeOrder(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
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
            'apply_discount' => ['sometimes', 'boolean'],
        ]);

        $validated['fulfilment_type'] = $validated['fulfilment_type'] ?? $validated['type'] ?? null;

        if ($validated['fulfilment_type'] === null) {
            return response()->json([
                'message' => 'Choose pickup or delivery before placing your order.',
                'error_code' => 'FULFILMENT_TYPE_REQUIRED',
            ], 422);
        }

        $quote = app(CheckoutQuote::class)->build($user, $validated);

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
                if ((float) $quote['discount']['amount'] > 0 && $this->discountSpentToday($user)) {
                    throw new DiscountAlreadyUsed;
                }

                $order = Order::create([
                    'user_id' => $user->id,
                    'order_type' => $quote['fulfilment_type'],
                    'address_id' => $isDelivery ? ($destination['address_id'] ?? null) : null,
                    'status' => OrderStatus::PLACED,
                    'subtotal' => $quote['subtotal'],
                    'discount_amount' => $quote['discount']['amount'],
                    'delivery_fee' => $quote['delivery_fee'],
                    'delivery_distance_km' => $isDelivery ? ($quote['delivery']['distance_km'] ?? null) : null,
                    'total_amount' => $quote['total'],
                    'total_price' => $quote['total'],
                    'pickup_at' => $isDelivery ? null : ($quote['pickup']['requested_at'] ?? null),
                    'full_address' => $isDelivery ? ($destination['full_address'] ?? null) : null,
                    'latitude' => $isDelivery ? ($destination['latitude'] ?? null) : null,
                    'longitude' => $isDelivery ? ($destination['longitude'] ?? null) : null,
                    'location' => $isDelivery ? ($destination['locality'] ?? null) : null,
                    'delivery_note' => $isDelivery ? ($destination['delivery_note'] ?? null) : null,
                    'payment_status' => 'unpaid',
                ]);

                foreach ($quote['items'] as $line) {
                    $item = OrderItem::create([
                        'order_id' => $order->id,
                        'food_id' => $line['food_id'],
                        'quantity' => $line['quantity'],
                        'price' => $line['unit_price'],
                        'unit_price' => $line['unit_price'],
                        'subtotal' => $line['subtotal'],
                    ]);

                    foreach ($line['addons'] ?? [] as $addon) {
                        OrderItemAddon::create([
                            'order_item_id' => $item->id,
                            'addon_id' => $addon['id'],
                            'unit_price' => $addon['addon_price'],
                        ]);
                    }
                }

                CartItem::where('user_id', $user->id)->whereIn('id', $selectedIds)->delete();

                return $order;
            });
        } catch (DiscountAlreadyUsed) {
            return response()->json([
                'message' => 'You have already used your discount today. It resets tomorrow.',
                'error_code' => 'DISCOUNT_ALREADY_USED',
            ], 409);
        } catch (\Throwable $e) {
            Log::error('Failed to place an order.', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Failed to place order'], 500);
        }

        $this->announcer->announce($order, 'create', 'create');

        return response()->json([
            'message' => 'Order Placed',
            'order' => $order,
        ], 201);
    }

    private function discountSpentToday(User $user): bool
    {
        $today = CarbonImmutable::now(Discount::USAGE_TIMEZONE);

        return $user->orders()
            ->where('discount_amount', '>', 0)
            ->whereBetween('created_at', [
                $today->startOfDay()->utc(),
                $today->endOfDay()->utc(),
            ])
            ->exists();
    }

    public function cancelOrder(Request $request, $orderId)
    {
        $user = $request->user();
        $order = $user->Orders()->where('id', $orderId)->first();

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if (! OrderStatus::isCustomerCancellable((string) $order->status)) {
            return response()->json([
                'message' => $order->isTerminal()
                    ? 'This order is already '.strtolower(OrderStatus::label((string) $order->status)).'.'
                    : 'This order has already been confirmed. Ask a store agent to cancel it for you.',
                'error_code' => $order->isTerminal()
                    ? 'ORDER_ALREADY_CLOSED'
                    : 'CANCELLATION_REQUIRES_APPROVAL',
            ], 400);
        }

        $order->status = OrderStatus::CANCELLED;
        $order->save();

        $this->announcer->announce($order, 'cancelled', OrderStatus::CANCELLED);

        return response()->json([
            'message' => 'Order cancelled successfully',
            'order' => new OrderTrackingResource($order->load('items.food', 'items.addons', 'user')),
        ], 200);
    }

    public function updateOrderStatus(Request $request, $orderId)
    {
        $this->authorize('isAdmin', Order::class);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(OrderStatus::all())],
        ]);

        $order = Order::find($orderId);

        if (! $order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        $target = $validated['status'];

        if ($order->status === $target) {
            return response()->json(['message' => 'Order status updated', 'order' => $order], 200);
        }

        if (! $order->canTransitionTo($target)) {
            $allowed = OrderStatus::transitionsFrom((string) $order->status, $order->order_type);

            return response()->json([
                'message' => $allowed === []
                    ? 'This order is already '.strtolower(OrderStatus::label((string) $order->status)).'.'
                    : 'An order that is '.strtolower(OrderStatus::label((string) $order->status))
                        .' can only move to '
                        .implode(' or ', array_map(
                            fn (string $status) => strtolower(OrderStatus::label($status)),
                            $allowed
                        )).'.',
                'error_code' => 'INVALID_STATUS_TRANSITION',
                'status' => $order->status,
                'allowed' => $allowed,
            ], 422);
        }

        if ($target === OrderStatus::DELIVERED) {
            $this->authorize('confirmReceipt', $order);
        }

        $order->status = $target;
        $order->save();

        $this->announcer->statusChanged($order);

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

        $this->announcer->announce($order, 'update', 'update');

        return response()->json(['message' => 'Order status updated', 'order' => $order], 200);
    }

    public function allOrders(Request $request)
    {
        $this->authorize('isAdmin', Order::class);

        $user = $request->user();
        if (! $user || ! in_array($user->role, ['admin', 'super_admin'], true)) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in([...OrderStatus::all(), 'all'])],
            'category' => ['sometimes', 'string', 'max:100'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $status = $validated['status'] ?? null;
        $category = $validated['category'] ?? null;

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
