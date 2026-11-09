<?php

namespace App\Http\Controllers;

use App\Http\Resources\StockItemResource;
use App\Models\Category;
use App\Models\Food;
use App\Models\User;
use App\Services\Stock\StockReservation;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Store Agent's stock page (BR-29, UC-MENU-016, UC-OPS-008).
 *
 * Reading is open to both staff roles. Changing is the Store Agent's alone: the
 * routes for update() and availability() sit in the `role:admin` group, an
 * exact match that leaves the Store Manager out. Deliberately not guarded with
 * authorize('isAdmin'), which admits super_admin and would reopen that boundary.
 * Nothing here touches a name, price, category or photo - those are the Store
 * Manager's catalogue.
 */
class StockController extends Controller
{
    public const MAX_COUNT = 9999;

    public const MAX_BULK = 500;

    private const BULK_RULES = [
        'food_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_BULK],
        'food_ids.*' => ['integer', 'distinct'],
    ];

    public function __construct(private StockReservation $stock) {}

    /** GET /api/agent/stock - every dish, switched off or not. */
    public function index(Request $request)
    {
        $foods = Food::query()
            ->with('category')
            ->withHeldQuantity()
            ->orderBy('food_name')
            ->get();

        $summary = [
            'total' => $foods->count(),
            'in_stock' => $foods->where('stock_status', Food::STOCK_IN)->count(),
            'low_stock' => $foods->where('stock_status', Food::STOCK_LOW)->count(),
            'out_of_stock' => $foods->where('stock_status', Food::STOCK_OUT)->count(),
            'untracked' => $foods->where('stock_status', Food::STOCK_UNTRACKED)->count(),
            'switched_off' => $foods->where('is_available', false)->count(),
        ];

        return response()->json([
            'data' => StockItemResource::collection($foods),
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'summary' => $summary,
            'meta' => [
                'low_stock_threshold' => Food::LOW_STOCK_THRESHOLD,
                'max_count' => self::MAX_COUNT,
                'can_manage' => $request->user()?->role === User::ROLE_ADMIN,
            ],
        ]);
    }

    /**
     * PATCH /api/foods/{food}/stock
     *
     *   {"adjust_by": 3}                      +/- a few units, applied under the lock
     *   {"stock_quantity": 12, "expected": 9} a recount, refused if the count has moved
     *   {"stock_quantity": null}              stop counting: made to order
     */
    public function update(Request $request, Food $food)
    {
        $request->validate([
            'adjust_by' => ['sometimes', 'integer', 'not_in:0', 'min:-'.self::MAX_COUNT, 'max:'.self::MAX_COUNT],
            'stock_quantity' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:'.self::MAX_COUNT],
            'expected' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);

        $adjusting = $request->has('adjust_by');
        $setting = $request->exists('stock_quantity');

        if ($adjusting === $setting) {
            throw ValidationException::withMessages([
                'stock_quantity' => 'Send either adjust_by or stock_quantity.',
            ]);
        }

        if ($adjusting) {
            $updated = $this->stock->adjust($food, (int) $request->input('adjust_by'));

            if ($updated === null) {
                return response()->json([
                    'message' => $food->food_name.' is made to order, so it has no count to change. Start counting it first.',
                    'error_code' => 'STOCK_UNTRACKED',
                ], 422);
            }

            return $this->answer($updated, 'Stock updated.');
        }

        $count = $request->input('stock_quantity');
        $result = $this->stock->set(
            $food,
            $count === null ? null : (int) $count,
            $request->exists('expected'),
            $request->input('expected') === null ? null : (int) $request->input('expected'),
        );

        if ($result['conflict']) {
            return response()->json([
                'message' => 'The count for '.$food->food_name.' changed while you were editing it. Check the new figure and try again.',
                'error_code' => 'STOCK_CHANGED',
                'item' => new StockItemResource($result['food']->load('category')),
            ], 409);
        }

        return $this->answer(
            $result['food'],
            $count === null
                ? $food->food_name.' is now made to order.'
                : $food->food_name.' set to '.$count.($count === 0 ? ' - sold out.' : '.')
        );
    }

    /** PATCH /api/foods/{food}/availability - switch a dish on or off (UC-OPS-008). */
    public function availability(Request $request, Food $food)
    {
        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $food = $this->stock->setAvailability($food, (bool) $validated['is_available']);

        return $this->answer(
            $food,
            $food->is_available ? $food->food_name.' is back on the menu.' : $food->food_name.' is switched off.'
        );
    }

    /** PATCH /api/agent/stock/availability - the ticked dishes, together. */
    public function bulkAvailability(Request $request)
    {
        $validated = $request->validate(self::BULK_RULES + [
            'is_available' => ['required', 'boolean'],
        ]);

        $available = (bool) $validated['is_available'];
        $ids = $this->stock->setAvailabilityOf($validated['food_ids'], $available);

        return $this->answerMany($ids, $available ? ' back on the menu.' : ' turned off.');
    }

    /**
     * PATCH /api/agent/stock/count - the counts of the ticked dishes.
     *
     *   {"food_ids": [1, 2], "adjust_by": 1}       the same step for each
     *   {"food_ids": [1, 2], "stock_quantity": 0}  sold out; no other figure
     */
    public function bulkStock(Request $request)
    {
        $validated = $request->validate(self::BULK_RULES + [
            'adjust_by' => ['sometimes', 'integer', 'not_in:0', 'min:-'.self::MAX_COUNT, 'max:'.self::MAX_COUNT],
            'stock_quantity' => ['sometimes', 'integer', 'in:0'],
        ]);

        $adjusting = array_key_exists('adjust_by', $validated);

        if ($adjusting === array_key_exists('stock_quantity', $validated)) {
            throw ValidationException::withMessages([
                'stock_quantity' => 'Send either adjust_by or stock_quantity.',
            ]);
        }

        $ids = $adjusting
            ? $this->stock->adjustMany($validated['food_ids'], (int) $validated['adjust_by'], self::MAX_COUNT)
            : $this->stock->markSoldOut($validated['food_ids']);

        if ($ids === []) {
            return response()->json([
                'message' => 'None of these dishes has a count to change.',
                'error_code' => 'STOCK_UNTRACKED',
            ], 422);
        }

        return $this->answerMany($ids, $adjusting ? ' updated.' : ' marked sold out.');
    }

    /** @param  list<int>  $ids */
    private function answerMany(array $ids, string $outcome)
    {
        $foods = Food::query()->with('category')->withHeldQuantity()->whereKey($ids)->get();

        return response()->json([
            'message' => ($foods->count() === 1 ? '1 dish' : $foods->count().' dishes').$outcome,
            'items' => StockItemResource::collection($foods),
        ]);
    }

    private function answer(Food $food, string $message)
    {
        $fresh = Food::query()->with('category')->withHeldQuantity()->findOrFail($food->getKey());

        return response()->json([
            'message' => $message,
            'item' => new StockItemResource($fresh),
        ]);
    }
}
