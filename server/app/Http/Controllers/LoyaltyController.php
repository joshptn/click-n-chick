<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyTransaction;
use App\Services\Loyalty\LoyaltyPoints;
use Illuminate\Http\Request;

/**
 * A customer's loyalty points (UC-LOY-001; decided 2026-11-24): the balance,
 * the program's rates, and every point earned, spent and given back, newest
 * first.
 */
class LoyaltyController extends Controller
{
    public function __construct(private LoyaltyPoints $points) {}

    /** GET /api/loyalty */
    public function show(Request $request)
    {
        $user = $request->user();

        abort_unless(LoyaltyPoints::belongsTo($user), 403, 'Loyalty points are for customer accounts.');

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $history = LoyaltyTransaction::query()
            ->where('user_id', $user->getKey())
            ->with('order')
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'balance' => round((float) $user->loyalty_points, 2),
            'rates' => [
                // Points per peso of food, the VAT taken out: 0.01 is 1%.
                'earn_rate' => $this->points->earnRate(),
                'peso_per_point' => $this->points->pesoPerPoint(),
                'redeemable' => $this->points->redeemable(),
            ],
            'history' => collect($history->items())->map(fn (LoyaltyTransaction $row) => [
                'id' => $row->id,
                'points_change' => round((float) $row->points_change, 2),
                'reason' => $row->reason,
                'label' => $this->label($row),
                'order' => $row->order === null ? null : [
                    'id' => $row->order->id,
                    'reference' => $row->order->reference(),
                ],
                'created_at' => $row->created_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $history->currentPage(),
                'last_page' => $history->lastPage(),
                'total' => $history->total(),
                'per_page' => $history->perPage(),
            ],
        ]);
    }

    /** "Earned on Order 007", "Paid with points on Order 012", "Given back: Order 012 was cancelled". */
    private function label(LoyaltyTransaction $row): string
    {
        $title = $row->order?->title() ?? 'an order';

        return match ($row->reason) {
            LoyaltyTransaction::EARNED => 'Earned on '.$title,
            LoyaltyTransaction::REDEEMED => 'Paid with points on '.$title,
            LoyaltyTransaction::RETURNED => 'Given back from '.$title,
            default => 'Points adjusted',
        };
    }
}
