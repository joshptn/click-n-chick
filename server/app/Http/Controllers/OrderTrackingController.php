<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderTrackingResource;
use App\Models\Order;
use App\Services\Orders\AmendmentPolicy;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class OrderTrackingController extends Controller
{
    use AuthorizesRequests;

    private const WITH = ['items.food', 'items.addons', 'user', 'paidPayment'];

    public function __construct(
        private OrderAnnouncer $announcer,
        private AmendmentPolicy $amendment,
    ) {}

    /**
     * The customer's orders, by My Orders tab (redesigned 2026-11-26):
     *
     *  - today:     today's orders (not advance), whatever became of them, and
     *               any still open from before, until the store day ends;
     *  - advance:   advance orders whose collection day has not passed, or
     *               still open, whatever became of them;
     *  - past:      completed or delivered orders whose day has passed;
     *  - cancelled: every cancelled, declined or rejected order, at once
     *               (so one cancelled today shows in Today and here).
     *
     * `active` and `all` stay for the app's Notif tab. "Today" is the store's
     * date (Asia/Manila), not UTC's.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'filter' => ['sometimes', 'string', 'in:active,past,all,today,advance,cancelled'],
        ]);

        $filter = $validated['filter'] ?? 'all';
        $dayStart = app(StoreAvailability::class)->now()->startOfDay()->utc();

        $orders = $this->tab($request, $filter, $dayStart)
            ->with([...self::WITH, 'loyaltyTransactions'])
            ->when(
                $filter === 'advance',
                // Soonest collection first: the next one to come is on top.
                fn (Builder $query) => $query->orderBy('scheduled_for')->orderByDesc('created_at'),
                fn (Builder $query) => $query->latest('created_at'),
            )
            ->paginate((int) ($validated['per_page'] ?? 15));

        return OrderTrackingResource::collection($orders->items())
            ->additional([
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'total' => $orders->total(),
                    'per_page' => $orders->perPage(),
                ],
                'counts' => [
                    'active' => $this->tab($request, 'active', $dayStart)->count(),
                    'today' => $this->tab($request, 'today', $dayStart)->count(),
                    'advance' => $this->tab($request, 'advance', $dayStart)->count(),
                ],
            ]);
    }

    /** One tab's orders, as a query; an unpaid checkout is not an order yet. */
    private function tab(Request $request, string $filter, CarbonInterface $dayStart): Builder
    {
        $query = $request->user()->orders()->withoutUnpaidCheckouts()->getQuery();
        $open = fn (Builder $q) => $q->whereNotIn('status', OrderStatus::terminal());

        return match ($filter) {
            'active' => $query->where($open),
            'today' => $query->whereNull('scheduled_for')
                ->where(fn (Builder $q) => $q->where('created_at', '>=', $dayStart)->orWhere($open)),
            'advance' => $query->whereNotNull('scheduled_for')
                ->where(fn (Builder $q) => $q->where('scheduled_for', '>=', $dayStart)->orWhere($open)),
            'past' => $query->whereIn('status', [OrderStatus::COMPLETED, OrderStatus::DELIVERED])
                ->where(fn (Builder $q) => $q
                    ->where(fn (Builder $today) => $today->whereNull('scheduled_for')->where('created_at', '<', $dayStart))
                    ->orWhere(fn (Builder $ahead) => $ahead->whereNotNull('scheduled_for')->where('scheduled_for', '<', $dayStart))),
            'cancelled' => $query->whereIn('status', [OrderStatus::CANCELLED, OrderStatus::REJECTED]),
            default => $query,
        };
    }

    public function show(Request $request, Order $order)
    {
        $this->authorize('view', $order);

        // Not an order until it is paid; the payment screen reads it through
        // /orders/{order}/payment instead.
        abort_if($order->isUnpaidCheckout(), 404);

        return response()->json([
            'order' => new OrderTrackingResource($order->load(self::WITH)),
        ]);
    }

    public function confirmDetails(Request $request, Order $order)
    {
        $this->authorize('amend', $order);

        if (! $this->amendment->canConfirmDetails($order)) {
            return response()->json([
                'message' => $order->details_confirmed_at !== null
                    ? 'You have already confirmed these details.'
                    : 'Your order has already moved on, so there is nothing left to confirm.',
                'error_code' => 'NOTHING_TO_CONFIRM',
            ], 422);
        }

        $order->forceFill(['details_confirmed_at' => now()])->save();

        $this->announcer->announce($order, 'update');

        return response()->json([
            'message' => 'Thanks - we will get started.',
            'order' => new OrderTrackingResource($order->load(self::WITH)),
        ]);
    }

    public function confirmReceipt(Request $request, Order $order)
    {
        $this->authorize('confirmReceipt', $order);

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
}
