<?php

namespace App\Http\Controllers;

use App\Exceptions\DiscountAlreadyUsed;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\User;
use App\Services\Orders\AdvanceQuote;
use App\Services\Orders\DiscountUsage;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderNotice;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdvanceOrderController extends Controller implements HasMiddleware
{
    public function __construct(
        private AdvanceQuote $quote,
        private DiscountUsage $discountUsage,
        private OrderAnnouncer $announcer,
        private OrderNotice $notice,
    ) {}

    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum'),
        ];
    }

    public function quote(Request $request)
    {
        $this->assertCustomer($request);

        return response()->json($this->quote->build($request->user(), $this->input($request)));
    }

    public function submit(Request $request)
    {
        $this->assertCustomer($request);

        $user = $request->user();
        $input = $this->input($request);

        $quote = $this->quote->build($user, $input);

        if (! $quote['can_submit']) {
            return response()->json([
                'message' => $quote['blockers'][0]['message'] ?? 'This request cannot be sent yet.',
                'error_code' => $quote['blockers'][0]['code'] ?? 'ADVANCE_BLOCKED',
                'blockers' => $quote['blockers'],
                'quote' => $quote,
            ], 409);
        }

        $collectAt = CarbonImmutable::parse($quote['schedule']['requested_at']);
        $selectedIds = collect($quote['items'])->pluck('id')->all();

        try {
            $order = DB::transaction(function () use ($user, $quote, $selectedIds, $collectAt) {
                if ((float) $quote['discount']['amount'] > 0
                    && $this->discountUsage->usedOn($user, $collectAt)) {
                    throw new DiscountAlreadyUsed;
                }

                $order = Order::create([
                    'user_id' => $user->id,
                    'order_type' => StoreAvailability::TYPE_PICKUP,
                    'status' => OrderStatus::SUBMITTED,
                    'scheduled_for' => $collectAt,
                    'subtotal' => $quote['subtotal'],
                    'discount_amount' => $quote['discount']['amount'],
                    'delivery_fee' => 0,
                    'total_amount' => $quote['total'],
                    'total_price' => $quote['total'],
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
                'message' => 'You already have a discounted order for that date.',
                'error_code' => 'DISCOUNT_ALREADY_USED',
            ], 409);
        } catch (\Throwable $e) {
            Log::error('Failed to submit an advance order.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'Failed to send your request.'], 500);
        }

        $this->announcer->announce($order, 'create', OrderStatus::SUBMITTED);

        $this->announcer->toStaff($order, $this->notice->staffNewRequest($order));

        return response()->json([
            'message' => 'Request sent to the store.',
            'order' => [
                'id' => $order->id,
                'status' => $order->status,
                'status_label' => OrderStatus::label($order->status),
                'scheduled_for' => $order->scheduled_for?->toIso8601String(),
                'total_amount' => (float) $order->total_amount,
                'item_count' => $quote['item_count'],
            ],
        ], 201);
    }

    private function input(Request $request): array
    {
        return $request->validate([
            'cart_item_ids' => ['nullable', 'array'],
            'cart_item_ids.*' => ['integer'],
            'scheduled_for' => ['nullable', 'string', 'max:64'],
            'apply_discount' => ['sometimes', 'boolean'],
        ]);
    }

    private function assertCustomer(Request $request): void
    {
        abort_unless(
            $request->user()?->role === User::ROLE_CUSTOMER,
            403,
            'Advance ordering is for customer accounts.'
        );
    }
}
