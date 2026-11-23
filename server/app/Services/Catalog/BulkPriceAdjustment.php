<?php

namespace App\Services\Catalog;

use App\Http\Controllers\FoodController;
use App\Models\Category;
use App\Models\Food;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One peso amount added to or taken off every dish, or every dish in one
 * category (FR-07.7, UC-ADMIN-005; decided 2026-11-24).
 *
 * By a peso amount only, so whole-peso prices stay whole and nothing rounds.
 * Archived dishes change too, so one comes back in line with the rest of the
 * menu; deleted dishes do not. Add-ons are not menu prices and stay as they
 * are.
 *
 * All or nothing: if any dish would leave the range a single edit allows
 * (₱0 to FoodController::MAX_PRICE), nothing changes and those dishes are
 * named. Saved in one transaction under the dishes' row locks, then announced
 * once, as one notice, not one per dish.
 */
final class BulkPriceAdjustment
{
    public const OUT_OF_RANGE = 'PRICE_OUT_OF_RANGE';

    public const NO_DISHES = 'NO_DISHES';

    public function __construct(private PriceAnnouncer $announcer) {}

    /** What the change would do, changing nothing. */
    public function preview(int $amount, ?Category $category): array
    {
        return $this->plan($this->scope($category)->get(), $amount, $category);
    }

    /**
     * The plan as applied. When `error_code` is set, nothing was saved.
     *
     * @return array<string, mixed>
     */
    public function apply(int $amount, ?Category $category): array
    {
        $plan = DB::transaction(function () use ($amount, $category) {
            $foods = $this->scope($category)->lockForUpdate()->get();
            $plan = $this->plan($foods, $amount, $category);

            if ($plan['error_code'] === null) {
                $query = Food::query()->whereKey($foods->modelKeys());
                $amount > 0 ? $query->increment('price', $amount) : $query->decrement('price', -$amount);
            }

            return $plan;
        });

        if ($plan['error_code'] === null) {
            $this->announcer->announce($plan['notice'], array_column($plan['foods'], 'id'));
        }

        return $plan;
    }

    private function scope(?Category $category)
    {
        return Food::query()
            ->select(['id', 'food_name', 'price', 'category_id', 'is_available'])
            ->when($category, fn ($query) => $query->where('category_id', $category->getKey()))
            ->orderBy('food_name');
    }

    /** @param  Collection<int, Food>  $foods */
    private function plan(Collection $foods, int $amount, ?Category $category): array
    {
        $lines = $foods->map(fn (Food $food) => [
            'id' => (int) $food->id,
            'food_name' => $food->food_name,
            'previous' => (int) $food->price,
            'price' => (int) $food->price + $amount,
            // Off the menu (archived, or switched off by the Store Agent): changed all the same.
            'is_available' => (bool) $food->is_available,
        ])->values();

        $outOfRange = $lines
            ->filter(fn (array $line) => $line['price'] < 0 || $line['price'] > FoodController::MAX_PRICE)
            ->values()
            ->all();

        [$code, $message] = match (true) {
            $lines->isEmpty() => [self::NO_DISHES, $category
                ? 'There are no dishes in '.$category->name.' to change.'
                : 'There are no dishes to change.'],
            $outOfRange !== [] => [self::OUT_OF_RANGE, $this->outOfRangeMessage($outOfRange, $amount)],
            default => [null, null],
        };

        return [
            'amount' => $amount,
            'category' => $category ? ['id' => (int) $category->getKey(), 'name' => $category->name] : null,
            'count' => $lines->count(),
            'foods' => $lines->all(),
            'out_of_range' => $outOfRange,
            'notice' => $this->notice($amount, $category),
            'error_code' => $code,
            'message' => $message,
        ];
    }

    /** The notice every account receives, and the live event's message. */
    private function notice(int $amount, ?Category $category): string
    {
        $where = $category ? 'in '.$category->name : 'on the menu';
        $change = PriceAnnouncer::peso(abs($amount)).($amount > 0 ? ' more' : ' less');

        return "Every dish {$where} is now {$change}.";
    }

    /** @param  list<array<string, mixed>>  $outOfRange */
    private function outOfRangeMessage(array $outOfRange, int $amount): string
    {
        $names = collect($outOfRange)->pluck('food_name');
        $listed = $names->take(3)->implode(', ').($names->count() > 3 ? ' and '.($names->count() - 3).' more' : '');

        return $amount < 0
            ? "That would take {$listed} below ₱0. Nothing was changed."
            : 'That would take '.$listed.' above '.PriceAnnouncer::peso(FoodController::MAX_PRICE).'. Nothing was changed.';
    }
}
