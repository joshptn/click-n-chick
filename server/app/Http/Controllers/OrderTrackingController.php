<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderTrackingResource;
use App\Models\Order;
use App\Services\Orders\AmendmentPolicy;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

class OrderTrackingController extends Controller
{
    use AuthorizesRequests;

    private const WITH = ['items.food', 'items.addons', 'user'];

    public function __construct(
        private OrderAnnouncer $announcer,
        private AmendmentPolicy $amendment,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'filter' => ['sometimes', 'string', 'in:active,past,all'],
        ]);

        $filter = $validated['filter'] ?? 'all';

        $query = $request->user()->orders()
            ->with(self::WITH)
            ->latest('created_at');

        if ($filter === 'active') {
            $query->whereNotIn('status', OrderStatus::terminal());
        } elseif ($filter === 'past') {
            $query->whereIn('status', OrderStatus::terminal());
        }

        $orders = $query->paginate((int) ($validated['per_page'] ?? 15));

        return OrderTrackingResource::collection($orders->items())
            ->additional([
                'meta' => [
                    'current_page' => $orders->currentPage(),
                    'last_page' => $orders->lastPage(),
                    'total' => $orders->total(),
                    'per_page' => $orders->perPage(),
                ],
                'counts' => [
                    'active' => $request->user()->orders()
                        ->whereNotIn('status', OrderStatus::terminal())->count(),
                ],
            ]);
    }

    public function show(Request $request, Order $order)
    {
        $this->authorize('view', $order);

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
