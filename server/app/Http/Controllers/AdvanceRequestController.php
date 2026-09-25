<?php

namespace App\Http\Controllers;

use App\Http\Resources\AdvanceRequestResource;
use App\Models\Order;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Orders\OrderStatus;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdvanceRequestController extends Controller
{
    use AuthorizesRequests;

    private const WITH = ['items.food', 'user'];

    public function __construct(
        private StoreAvailability $store,
        private OrderAnnouncer $announcer,
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
                ->setTimezone($this->store->timezone())
                ->isSameDay($today)
        );

        return response()->json([
            'date' => $today->toDateString(),

            'awaiting_decision' => AdvanceRequestResource::collection($awaitingDecision),
            'awaiting_payment' => AdvanceRequestResource::collection($awaitingPayment),
            'scheduled' => AdvanceRequestResource::collection($scheduled),
            'due_today' => AdvanceRequestResource::collection($dueToday->values()),

            'summary' => [
                'awaiting_decision' => $awaitingDecision->count(),
                'awaiting_payment' => $awaitingPayment->count(),
                'scheduled' => $scheduled->count(),
                'due_today' => $dueToday->count(),
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
            ->orderBy('scheduled_for')
            ->get();
    }
}
