<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdvanceRequestResource;
use App\Models\Notification;
use App\Models\Order;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderNotice;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use App\Utils\Notification as Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdvanceRequestController extends Controller
{
    use AuthorizesRequests;

    private const WITH = ['items.food', 'items.addons', 'user'];

    public const REMINDER_COOLDOWN_MINUTES = 60;

    /** How far back the page's Closed column looks, and how many it lists. */
    private const CLOSED_DAYS = 7;

    private const CLOSED_SHOWN = 50;

    public function __construct(
        private StoreAvailability $store,
        private OrderAnnouncer $announcer,
        private OrderNotice $notice,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Order::class);

        $today = CarbonImmutable::now($this->store->timezone());

        $awaitingDecision = $this->requests([OrderStatus::SUBMITTED]);
        $awaitingPayment = $this->requests([OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT]);
        $scheduled = $this->requests([OrderStatus::CONFIRMED, OrderStatus::SCHEDULED]);

        $dueToday = $scheduled->filter(
            fn (Order $order) => $order->scheduled_for
                ->toImmutable()
                ->setTimezone($this->store->timezone())
                ->isSameDay($today)
        );

        // From preparing on, an advance order runs the ordinary pickup chain but
        // still holds no queue number (BR-22c), so the day's board never lists
        // it. It stays here until it is collected.
        $inKitchen = $this->requests([OrderStatus::PREPARING, OrderStatus::READY_FOR_PICKUP]);

        $closedSince = CarbonImmutable::now()->subDays(self::CLOSED_DAYS);
        $closedQuery = Order::query()
            ->whereNotNull('scheduled_for')
            ->whereIn('status', [OrderStatus::COMPLETED, OrderStatus::REJECTED, OrderStatus::CANCELLED])
            ->where('closed_at', '>=', $closedSince);

        $closedCount = (clone $closedQuery)->count();
        $closed = $closedQuery
            ->with(self::WITH)
            ->orderByDesc('closed_at')
            ->orderByDesc('id')
            ->limit(self::CLOSED_SHOWN)
            ->get();

        return response()->json([
            'date' => $today->toDateString(),

            'awaiting_decision' => AdvanceRequestResource::collection($awaitingDecision),
            'awaiting_payment' => AdvanceRequestResource::collection($awaitingPayment),
            'scheduled' => AdvanceRequestResource::collection($scheduled),
            'due_today' => AdvanceRequestResource::collection($dueToday->values()),
            'in_kitchen' => AdvanceRequestResource::collection($inKitchen),
            'closed' => AdvanceRequestResource::collection($closed),

            'summary' => [
                'awaiting_decision' => $awaitingDecision->count(),
                'awaiting_payment' => $awaitingPayment->count(),
                'scheduled' => $scheduled->count(),
                'due_today' => $dueToday->count(),
                'in_kitchen' => $inKitchen->count(),
                'closed_recently' => $closedCount,
                'closed_days' => self::CLOSED_DAYS,
            ],
        ]);
    }

    public function show(Request $request, Order $order)
    {
        $this->authorize('view', $order);

        abort_unless($order->isAdvance(), 404);

        return response()->json([
            'order' => new AdvanceRequestResource($order->load(self::WITH)),
        ]);
    }

    public function accept(Request $request, Order $order)
    {
        $this->authorize('decideAdvance', $order);

        if ($order->status !== OrderStatus::SUBMITTED) {
            return response()->json($this->alreadyAnswered($order), 422);
        }

        // A request left unanswered past its own collection date can no longer
        // be honoured; accepting it would ask the customer to pay for a day that
        // is gone. Declining tells them to pick another date.
        $collect = $order->scheduled_for?->toImmutable()->setTimezone($this->store->timezone());

        if ($collect !== null && $collect->startOfDay()->lessThan(CarbonImmutable::now($this->store->timezone())->startOfDay())) {
            return response()->json([
                'message' => 'The collection date for this request has passed. Decline it so the customer can choose another date.',
                'error_code' => 'ADVANCE_DATE_PASSED',
                'status' => $order->status,
            ], 422);
        }

        DB::transaction(function () use ($order) {
            $order->accepted_at = CarbonImmutable::now();
            $order->status = OrderStatus::ACCEPTED;
            $order->save();

            $order->status = OrderStatus::AWAITING_PAYMENT;
            $order->save();
        });

        $this->announcer->statusChanged($order);

        return response()->json([
            'message' => 'Request accepted. The customer can now pay for it.',
            'order' => new AdvanceRequestResource($order->load(self::WITH)),
        ]);
    }

    public function reject(Request $request, Order $order)
    {
        $this->authorize('decideAdvance', $order);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        if ($order->status !== OrderStatus::SUBMITTED) {
            return response()->json($this->alreadyAnswered($order), 422);
        }

        $order->forceFill([
            'status' => OrderStatus::REJECTED,
            'cancellation_reason' => trim($validated['reason']),
            'cancelled_by' => $request->user()->getKey(),
            'refund_owed' => 0,
        ])->save();

        $this->announcer->statusChanged($order);

        return response()->json([
            'message' => 'Request rejected. The customer has been told why.',
            'order' => new AdvanceRequestResource($order->load(self::WITH)),
        ]);
    }

    public function remind(Request $request, Order $order)
    {
        $this->authorize('decideAdvance', $order);

        if ($order->isPaid()) {
            return response()->json([
                'message' => 'This order has already been paid for.',
                'error_code' => 'ALREADY_PAID',
                'status' => $order->status,
            ], 422);
        }

        if (! in_array((string) $order->status, [OrderStatus::ACCEPTED, OrderStatus::AWAITING_PAYMENT], true)) {
            return response()->json([
                'message' => 'Only an accepted request awaiting payment can be chased.',
                'error_code' => 'NOT_AWAITING_PAYMENT',
                'status' => $order->status,
            ], 422);
        }

        if ($order->user_id === null) {
            return response()->json([
                'message' => 'This order has no account to notify.',
                'error_code' => 'NO_RECIPIENT',
            ], 422);
        }

        $sentRecently = Notification::query()
            ->where('order_id', $order->getKey())
            ->where('notification_type', OrderNotice::TYPE_ADVANCE_PAYMENT)
            ->where('created_at', '>=', CarbonImmutable::now()->subMinutes(self::REMINDER_COOLDOWN_MINUTES))
            ->exists();

        if ($sentRecently) {
            return response()->json([
                'message' => 'This customer was reminded within the last hour. Give them a little longer.',
                'error_code' => 'REMINDER_TOO_SOON',
            ], 429);
        }

        Notifier::send((int) $order->user_id, $this->notice->paymentReminder($order), $order);

        return response()->json([
            'message' => 'Reminder sent to the customer.',
            'payment_due_at' => $order->paymentDueAt()?->toIso8601String(),
        ]);
    }

    private function alreadyAnswered(Order $order): array
    {
        return [
            'message' => 'This request has already been answered. It is '
                .strtolower(OrderStatus::label((string) $order->status)).'.',
            'error_code' => 'ALREADY_ANSWERED',
            'status' => $order->status,
        ];
    }

    private function requests(array $statuses)
    {
        return Order::query()
            ->whereNotNull('scheduled_for')
            ->whereIn('status', $statuses)
            ->with(self::WITH)
            // When the customer was last chased for payment, so the page can say
            // so and hold the button through the cooldown remind() enforces.
            ->withMax(
                ['notifications as last_reminded_at' => fn ($query) => $query->where('notification_type', OrderNotice::TYPE_ADVANCE_PAYMENT)],
                'created_at'
            )
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->get();
    }
}
