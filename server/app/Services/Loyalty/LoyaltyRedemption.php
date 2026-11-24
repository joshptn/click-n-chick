<?php

namespace App\Services\Loyalty;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The dishes a customer pays for with points at checkout (decided 2026-11-24).
 *
 * Asked for as `redeem: [{cart_item_id, quantity}]`: that many units of the
 * line's dish are paid with points, at the dish's menu price; the add-ons
 * picked on them are paid as usual. Any number of units, as long as the
 * balance covers them all. A request that cannot be met is a blocker, and the
 * quote's figures are then shown without it.
 */
final class LoyaltyRedemption
{
    /** The checkout field, for every endpoint that quotes or places an order. */
    public const RULES = [
        'redeem' => ['sometimes', 'array', 'max:50'],
        'redeem.*.cart_item_id' => ['required', 'integer'],
        'redeem.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
    ];

    public function __construct(private LoyaltyPoints $points) {}

    /**
     * @param  Collection<int, array<string, mixed>>  $lines  quote lines, keyed by the cart item's `id`
     * @param  list<array{cart_item_id: int|string, quantity: int|string}>  $requested
     * @return array{lines: Collection<int, array<string, mixed>>, amount: float, points: float, blocker: array{code: string, message: string}|null}
     */
    public function apply(?User $user, Collection $lines, array $requested): array
    {
        $lines = $lines->map(fn (array $line) => $line + [
            // One unit of the dish, in points, for the screen.
            'points_price' => $this->points->pointsFor((float) $line['base_price']),
            'redeemed_quantity' => 0,
        ]);

        $wanted = collect($requested)
            ->groupBy(fn ($entry) => (int) $entry['cart_item_id'])
            ->map(fn ($entries) => (int) $entries->sum(fn ($entry) => (int) $entry['quantity']))
            ->filter(fn (int $quantity) => $quantity > 0);

        $none = ['lines' => $lines, 'amount' => 0.0, 'points' => 0.0, 'blocker' => null];

        if ($wanted->isEmpty()) {
            return $none;
        }

        $refuse = fn (string $code, string $message) => ['blocker' => compact('code', 'message')] + $none;

        if (! LoyaltyPoints::belongsTo($user)) {
            return $refuse('LOYALTY_SIGN_IN', 'Sign in to your account to pay with points.');
        }

        if (! $this->points->redeemable()) {
            return $refuse('LOYALTY_OFF', 'Paying with points is not available right now.');
        }

        foreach ($wanted as $id => $quantity) {
            $line = $lines->firstWhere('id', $id);

            if ($line === null) {
                return $refuse('LOYALTY_ITEM_NOT_IN_ORDER', 'An item you picked to pay with points is not in this order.');
            }

            if ($quantity > (int) $line['quantity']) {
                return $refuse('LOYALTY_TOO_MANY', 'You picked more '.$line['food_name'].' to pay with points than the order has.');
            }
        }

        $chosen = $lines->map(fn (array $line) => ['redeemed_quantity' => $wanted->get($line['id'], 0)] + $line);

        $amount = round((float) $chosen->sum(fn (array $line) => (float) $line['base_price'] * $line['redeemed_quantity']), 2);
        $points = $this->points->pointsFor($amount);
        $balance = (float) $user->loyalty_points;

        if ($points > $balance + 0.0001) {
            $picked = $chosen->filter(fn (array $line) => $line['redeemed_quantity'] > 0);
            $what = $picked->count() === 1 && $picked->first()['redeemed_quantity'] === 1
                ? $picked->first()['food_name']
                : 'what you picked';

            return $refuse('POINTS_NOT_ENOUGH', sprintf(
                'You have %s points, and %s needs %s.',
                self::format($balance),
                $what,
                self::format($points),
            ));
        }

        return ['lines' => $chosen, 'amount' => $amount, 'points' => $points, 'blocker' => null];
    }

    /** "1,234.50" */
    public static function format(float $points): string
    {
        return number_format($points, 2);
    }
}
