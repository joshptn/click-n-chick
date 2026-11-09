<?php

namespace App\Services\Stock;

use App\Events\MenuBroadcast;
use App\Exceptions\InsufficientStock;
use App\Models\Food;
use App\Models\Order;
use App\Models\StockHold;
use App\Services\Orders\OrderStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything that moves stock (GATE-7).
 *
 * The count on foods.stock_quantity is what is left on the shelf. Orders take
 * from it through stock_holds, under a row lock on the food, so two customers
 * cannot both buy the last units (FR-08.3):
 *
 *   hold()     sets units aside for an order. They stop being available at once
 *              but stay on the count while the customer pays (FR-04.5).
 *   confirm()  the sale. The units come off the count.
 *   release()  gives units back: an unpaid hold lapses, or a sale is cancelled
 *              before the kitchen starts and its units return to the count.
 *
 * Until payment exists (GATE-6) a placed order is a sale, so placement holds and
 * confirms in one step. Payment will move confirm() to its success callback and
 * let expireStale() lapse the holds nobody paid for.
 *
 * Advance orders never come through here (BR-16d). Untracked items (a null
 * count, made to order) are never held.
 */
class StockReservation
{
    /** The payment session's lifetime (FR-04.5), and so a hold's. */
    public const PAYMENT_HOLD_MINUTES = 10;

    /** Cancelled at or before these, the food was never started: its units return. */
    public const RESTOCK_THROUGH = [OrderStatus::PLACED, OrderStatus::CONFIRMED];

    /**
     * Set aside what an order needs, or refuse the whole order.
     *
     * @param  iterable<array{food_id: int, quantity: int}>  $lines
     *
     * @throws InsufficientStock
     */
    public function hold(Order $order, iterable $lines): void
    {
        $wanted = collect($lines)
            ->groupBy(fn ($line) => (int) $line['food_id'])
            ->map(fn (Collection $group) => (int) $group->sum('quantity'))
            ->filter(fn (int $quantity) => $quantity > 0);

        if ($wanted->isEmpty()) {
            return;
        }

        $touched = DB::transaction(function () use ($order, $wanted) {
            // Ascending ids: every reserver locks in the same order, so two
            // checkouts sharing items queue behind each other instead of
            // deadlocking.
            $foods = Food::query()
                ->whereKey($wanted->keys()->all())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $held = $this->heldFor($foods->keys());
            $shortages = [];

            foreach ($wanted as $foodId => $quantity) {
                $food = $foods->get($foodId);

                if ($food === null || ! $food->is_available) {
                    $shortages[] = $this->shortage($foodId, $food, $quantity, 0);

                    continue;
                }

                if ($food->stock_quantity === null) {
                    continue;
                }

                $available = max(0, $food->stock_quantity - (int) ($held[$foodId] ?? 0));

                if ($quantity > $available) {
                    $shortages[] = $this->shortage($foodId, $food, $quantity, $available);
                }
            }

            if ($shortages !== []) {
                throw new InsufficientStock($shortages);
            }

            $expiresAt = now()->addMinutes(self::PAYMENT_HOLD_MINUTES);
            $touched = [];

            foreach ($wanted as $foodId => $quantity) {
                if ($foods[$foodId]->stock_quantity === null) {
                    continue;
                }

                StockHold::create([
                    'food_id' => $foodId,
                    'order_id' => $order->getKey(),
                    'quantity' => $quantity,
                    'status' => StockHold::HOLDING,
                    'expires_at' => $expiresAt,
                ]);

                $touched[] = $foodId;
            }

            return $touched;
        });

        $this->announce($touched);
    }

    /** The sale: an order's held units come off the count. */
    public function confirm(Order $order): void
    {
        $touched = DB::transaction(function () use ($order) {
            $holds = $order->stockHolds()->where('status', StockHold::HOLDING)->lockForUpdate()->get();

            if ($holds->isEmpty()) {
                return [];
            }

            $foods = $this->locked($holds->pluck('food_id'));

            foreach ($holds as $hold) {
                $food = $foods->get($hold->food_id);

                if ($food !== null && $food->stock_quantity !== null) {
                    $food->stock_quantity = max(0, $food->stock_quantity - $hold->quantity);
                    $food->save();
                }

                $hold->forceFill(['status' => StockHold::CONFIRMED, 'expires_at' => null])->save();
            }

            return $holds->pluck('food_id')->unique()->values()->all();
        });

        $this->announce($touched);
    }

    /**
     * Give back what an order took.
     *
     * A hold still waiting on payment is simply dropped. A sale returns to the
     * count only when $restock says the kitchen never started on it.
     */
    public function release(Order $order, bool $restock): void
    {
        $touched = DB::transaction(function () use ($order, $restock) {
            $holds = $order->stockHolds()
                ->whereIn('status', [StockHold::HOLDING, StockHold::CONFIRMED])
                ->lockForUpdate()
                ->get();

            if ($holds->isEmpty()) {
                return [];
            }

            $foods = $this->locked($holds->pluck('food_id'));
            $touched = [];

            foreach ($holds as $hold) {
                if ($hold->status === StockHold::CONFIRMED) {
                    if (! $restock) {
                        continue;
                    }

                    $food = $foods->get($hold->food_id);

                    if ($food !== null && $food->stock_quantity !== null) {
                        $food->stock_quantity += $hold->quantity;
                        $food->save();
                    }
                }

                $hold->forceFill(['status' => StockHold::RELEASED, 'expires_at' => null])->save();
                $touched[] = $hold->food_id;
            }

            return array_values(array_unique($touched));
        });

        $this->announce($touched);
    }

    /** Called when an order is cancelled from any path (see Order::booted). */
    public function orderCancelled(Order $order, ?string $from): void
    {
        $this->release($order, in_array($from, self::RESTOCK_THROUGH, true));
    }

    /** Lapse every hold whose payment window has closed. Returns how many. */
    public function expireStale(): int
    {
        $touched = [];

        $count = DB::transaction(function () use (&$touched) {
            $holds = StockHold::query()
                ->where('status', StockHold::HOLDING)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($holds as $hold) {
                $hold->forceFill(['status' => StockHold::EXPIRED])->save();
            }

            $touched = $holds->pluck('food_id')->unique()->values()->all();

            return $holds->count();
        });

        $this->announce($touched);

        return $count;
    }

    // -----------------------------------------------------------------
    // The Store Agent's count (BR-29, UC-MENU-016)
    // -----------------------------------------------------------------

    /**
     * Move a counted item up or down by a few units.
     *
     * Relative, and applied under the row lock, so a sale landing between the
     * agent reading the count and pressing + is kept rather than overwritten.
     * Never below zero. Null when the item is not counted.
     */
    public function adjust(Food $food, int $by): ?Food
    {
        $updated = DB::transaction(function () use ($food, $by) {
            $locked = Food::query()->whereKey($food->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->stock_quantity === null) {
                return null;
            }

            $locked->stock_quantity = max(0, $locked->stock_quantity + $by);
            $locked->save();

            return $locked;
        });

        if ($updated !== null) {
            $this->announce([$updated->getKey()]);
        }

        return $updated;
    }

    /**
     * Set the count outright - a recount of the shelf - or stop counting (null:
     * made to order).
     *
     * When the agent says what they were looking at ($expected), a count that
     * has moved since is refused rather than overwritten: a sale in between
     * would otherwise be put back on the shelf. Returns the item and whether it
     * was refused.
     *
     * @return array{food: Food, conflict: bool}
     */
    public function set(Food $food, ?int $count, bool $checkExpected = false, ?int $expected = null): array
    {
        $result = DB::transaction(function () use ($food, $count, $checkExpected, $expected) {
            $locked = Food::query()->whereKey($food->getKey())->lockForUpdate()->firstOrFail();

            if ($checkExpected && $locked->stock_quantity !== $expected) {
                return ['food' => $locked, 'conflict' => true];
            }

            $locked->stock_quantity = $count;
            $locked->save();

            return ['food' => $locked, 'conflict' => false];
        });

        if (! $result['conflict']) {
            $this->announce([$result['food']->getKey()]);
        }

        return $result;
    }

    /**
     * Switch several dishes on or off together, all or none. Counts are left
     * alone.
     *
     * @param  list<int>  $foodIds
     * @return list<int> the dishes changed; one deleted meanwhile is skipped
     */
    public function setAvailabilityOf(array $foodIds, bool $available): array
    {
        $changed = DB::transaction(function () use ($foodIds, $available) {
            $foods = $this->locked(collect($foodIds));

            foreach ($foods as $food) {
                $food->is_available = $available;
                $food->save();
            }

            return $foods->keys()->all();
        });

        $this->announce($changed);

        return $changed;
    }

    /**
     * Move several counts by the same amount, kept between 0 and $ceiling.
     *
     * @param  list<int>  $foodIds
     * @return list<int> the dishes changed; made-to-order dishes have no count
     */
    public function adjustMany(array $foodIds, int $by, int $ceiling): array
    {
        return $this->changeCounts($foodIds, fn (int $count) => min($ceiling, max(0, $count + $by)));
    }

    /**
     * @param  list<int>  $foodIds
     * @return list<int> the dishes changed; made-to-order dishes have no count
     */
    public function markSoldOut(array $foodIds): array
    {
        return $this->changeCounts($foodIds, fn () => 0);
    }

    /** @return list<int> */
    private function changeCounts(array $foodIds, callable $next): array
    {
        $changed = DB::transaction(function () use ($foodIds, $next) {
            $foods = $this->locked(collect($foodIds))->whereNotNull('stock_quantity');

            foreach ($foods as $food) {
                $food->stock_quantity = $next($food->stock_quantity);
                $food->save();
            }

            return $foods->keys()->all();
        });

        $this->announce($changed);

        return $changed;
    }

    public function setAvailability(Food $food, bool $available): Food
    {
        $food->forceFill(['is_available' => $available])->save();

        $this->announce([$food->getKey()]);

        return $food;
    }

    // -----------------------------------------------------------------

    /** @return Collection<int, Food> */
    private function locked(Collection $foodIds): Collection
    {
        return Food::query()
            ->whereKey($foodIds->unique()->values()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /** @return Collection<int, int> units under live holds, by food id */
    private function heldFor(Collection $foodIds): Collection
    {
        return StockHold::query()
            ->active()
            ->whereIn('food_id', $foodIds->all())
            ->groupBy('food_id')
            ->selectRaw('food_id, sum(quantity) as held')
            ->pluck('held', 'food_id')
            ->map(fn ($held) => (int) $held);
    }

    private function shortage(int $foodId, ?Food $food, int $requested, int $available): array
    {
        return [
            'food_id' => $foodId,
            'food_name' => $food?->food_name ?? 'An item',
            'requested' => $requested,
            'available' => $available,
        ];
    }

    /** Menus, carts and the agent's page all learn of a new count at once (UC-MENU-006). */
    private function announce(array $foodIds): void
    {
        if ($foodIds === []) {
            return;
        }

        Food::query()
            ->with('category')
            ->withHeldQuantity()
            ->whereKey(array_values(array_unique($foodIds)))
            ->get()
            ->each(fn (Food $food) => MenuBroadcast::dispatch($food, 'updated'));
    }
}
