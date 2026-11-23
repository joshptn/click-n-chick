<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Food;
use App\Services\Auth\PasswordConfirmation;
use App\Services\Catalog\BulkPriceAdjustment;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * The Store Manager's bulk price change (FR-07.7, UC-ADMIN-005).
 *
 *   POST /api/foods/prices/preview  {amount, category_id?}            what it would do
 *   POST /api/foods/prices          {amount, category_id?, password?} do it
 *
 * `amount` is whole pesos, added (positive) or taken off (negative), on every
 * dish or every dish in `category_id`. The change itself asks for the
 * password like a single price edit (FR-01.16); the preview changes nothing
 * and needs none.
 */
class MenuPriceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private BulkPriceAdjustment $prices) {}

    public function preview(Request $request)
    {
        $this->authorize('isSuperAdmin', Food::class);

        [$amount, $category] = $this->validated($request);

        return response()->json(['data' => $this->present($this->prices->preview($amount, $category))]);
    }

    public function apply(Request $request)
    {
        $this->authorize('isSuperAdmin', Food::class);

        [$amount, $category] = $this->validated($request);

        if ($failure = app(PasswordConfirmation::class)->challenge($request, 'change menu prices')) {
            return $failure;
        }

        $plan = $this->prices->apply($amount, $category);

        if ($plan['error_code'] !== null) {
            return response()->json([
                'message' => $plan['message'],
                'error_code' => $plan['error_code'],
                'data' => $this->present($plan),
            ], 422);
        }

        return response()->json([
            'message' => $plan['count'] === 1 ? 'The price of 1 dish was changed.' : "The prices of {$plan['count']} dishes were changed.",
            'data' => $this->present($plan),
        ]);
    }

    /** @return array{0: int, 1: Category|null} */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'min:-'.FoodController::MAX_PRICE, 'max:'.FoodController::MAX_PRICE],
            'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'],
        ], [
            'amount.not_in' => 'Enter how many pesos to add or take off.',
        ]);

        $category = isset($validated['category_id']) ? Category::find($validated['category_id']) : null;

        return [(int) $validated['amount'], $category];
    }

    private function present(array $plan): array
    {
        return [
            'amount' => $plan['amount'],
            'category' => $plan['category'],
            'count' => $plan['count'],
            'foods' => $plan['foods'],
            'out_of_range' => $plan['out_of_range'],
            'notice' => $plan['notice'],
            'can_apply' => $plan['error_code'] === null,
            'error_code' => $plan['error_code'],
            'message' => $plan['message'],
        ];
    }
}
