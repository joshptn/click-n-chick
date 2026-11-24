<?php

namespace App\Services\Loyalty;

use App\Models\Discount;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\Orders\OrderStatus;
use Illuminate\Support\Facades\DB;

/**
 * The BES Loyalty Program's points (decided 2026-11-24).
 *
 * Earning: a customer account earns when its paid order is completed or
 * delivered: 1% of the food (dishes and add-ons) at menu prices, before the
 * SC/PWD discount, with the VAT taken out, and without the dishes paid with
 * points. Delivery fees never count. Points keep centavos: ₱560 of food earns
 * 5.00 points (₱560 / 1.12 = ₱500, 1% of that).
 *
 * Redeeming: a dish is paid for with points at its menu price (1 point = ₱1),
 * when the balance covers it. The points are held from the order as soon as
 * it is placed (immediate) or its payment opens (advance), so two checkouts
 * cannot spend them twice, and given back if it is cancelled or declined.
 *
 * Both rates are the Store Manager's settings (Setting::LOYALTY_*), which
 * default to the program's 1% and ₱1.
 *
 * The balance is users.loyalty_points; every change is also a ledger row, so
 * the Store Manager's totals come from the ledger.
 */
final class LoyaltyPoints
{
    /** Points per peso of food, VAT taken out. */
    public const EARN_RATE = 0.01;

    /** What one point pays for. */
    public const PESO_PER_POINT = 1.0;

    public function earnRate(): float
    {
        return max(0.0, Setting::number(Setting::LOYALTY_POINTS_PER_PESO, self::EARN_RATE));
    }

    public function pesoPerPoint(): float
    {
        return max(0.0, Setting::number(Setting::LOYALTY_PESO_PER_POINT, self::PESO_PER_POINT));
    }

    /** Paying with points is off while a point is worth nothing. */
    public function redeemable(): bool
    {
        return $this->pesoPerPoint() > 0;
    }

    /** Only a customer account earns and spends points; never a guest or the staff. */
    public static function belongsTo(?User $user): bool
    {
        return $user !== null && $user->role === User::ROLE_CUSTOMER;
    }

    /** The points a peso amount costs. */
    public function pointsFor(float $pesos): float
    {
        $value = $this->pesoPerPoint();

        return $value > 0 ? round($pesos / $value, 2) : 0.0;
    }

    /** What an amount of food at menu prices earns: VAT out, then the rate. */
    public function earnedOn(float $food): float
    {
        if ($food <= 0) {
            return 0.0;
        }

        $exclusive = $food / (1 + Discount::VAT_RATE / 100);

        return round($exclusive * $this->earnRate(), 2);
    }

    /** What a completed order earns: its food, less the dishes paid with points. */
    public function earningsOf(Order $order): float
    {
        return $this->earnedOn(round((float) $order->subtotal - (float) $order->points_amount, 2));
    }

    /** The points an order has taken and not given back. */
    public function heldFor(Order $order): float
    {
        $net = (float) LoyaltyTransaction::query()
            ->where('order_id', $order->getKey())
            ->whereIn('reason', [LoyaltyTransaction::REDEEMED, LoyaltyTransaction::RETURNED])
            ->sum('points_change');

        return round(-$net, 2);
    }

    public function earnedFor(Order $order): float
    {
        return round((float) LoyaltyTransaction::query()
            ->where('order_id', $order->getKey())
            ->where('reason', LoyaltyTransaction::EARNED)
            ->sum('points_change'), 2);
    }

    /**
     * Makes what the order holds match what its dishes paid with points cost
     * now (orders.points_amount at today's point value): takes the difference
     * when it costs more, gives it back when it costs less. False, changing
     * nothing, when the balance cannot cover what more it needs.
     */
    public function hold(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $owed = round($this->pointsFor((float) $order->points_amount) - $this->heldFor($order), 2);

            if (abs($owed) < 0.005) {
                return true;
            }

            $user = $order->user_id === null
                ? null
                : User::query()->whereKey($order->user_id)->lockForUpdate()->first();

            if ($user === null) {
                return false;
            }

            if ($owed > 0 && (float) $user->loyalty_points + 0.0001 < $owed) {
                return false;
            }

            $this->move($user, $order, -$owed, $owed > 0 ? LoyaltyTransaction::REDEEMED : LoyaltyTransaction::RETURNED);

            return true;
        });
    }

    /** Everything the order holds goes back to its customer. */
    public function release(Order $order): void
    {
        DB::transaction(function () use ($order) {
            $held = $this->heldFor($order);

            if ($held < 0.005 || $order->user_id === null) {
                return;
            }

            $user = User::query()->whereKey($order->user_id)->lockForUpdate()->first();

            if ($user !== null) {
                $this->move($user, $order, $held, LoyaltyTransaction::RETURNED);
            }
        });
    }

    /** Credits a completed order's points, once. */
    public function earn(Order $order): void
    {
        if ($order->user_id === null) {
            return;
        }

        DB::transaction(function () use ($order) {
            $user = User::query()->whereKey($order->user_id)->lockForUpdate()->first();

            if (! self::belongsTo($user)) {
                return;
            }

            $already = LoyaltyTransaction::query()
                ->where('order_id', $order->getKey())
                ->where('reason', LoyaltyTransaction::EARNED)
                ->exists();

            $points = $this->earningsOf($order);

            if ($already || $points <= 0) {
                return;
            }

            $this->move($user, $order, $points, LoyaltyTransaction::EARNED);
        });
    }

    /**
     * Called from Order's updated event on every status change, so no path
     * that ends an order can forget its points.
     */
    public function statusChanged(Order $order): void
    {
        $status = (string) $order->status;

        if (in_array($status, [OrderStatus::CANCELLED, OrderStatus::REJECTED], true)) {
            $this->release($order);
        } elseif (in_array($status, OrderStatus::fulfilled(), true)) {
            $this->earn($order);
        }
    }

    /**
     * The Store Manager's loyalty figures. Issued and Redeemed come from the
     * ledger, so they keep a deleted account's history; Outstanding is what
     * the current accounts hold. Points held by an order not yet paid count
     * as redeemed, since they are out of the balance.
     *
     * @return array{issued: float, redeemed: float, outstanding: float}
     */
    public function totals(): array
    {
        $issued = (float) LoyaltyTransaction::query()->where('reason', LoyaltyTransaction::EARNED)->sum('points_change');

        $spent = (float) LoyaltyTransaction::query()
            ->whereIn('reason', [LoyaltyTransaction::REDEEMED, LoyaltyTransaction::RETURNED])
            ->sum('points_change');

        return [
            'issued' => round($issued, 2),
            'redeemed' => round(-$spent, 2),
            'outstanding' => round((float) User::query()->sum('loyalty_points'), 2),
        ];
    }

    private function move(User $user, Order $order, float $change, string $reason): void
    {
        $change = round($change, 2);

        LoyaltyTransaction::create([
            'user_id' => $user->getKey(),
            'order_id' => $order->getKey(),
            'points_change' => $change,
            'reason' => $reason,
        ]);

        $change >= 0
            ? User::query()->whereKey($user->getKey())->increment('loyalty_points', $change)
            : User::query()->whereKey($user->getKey())->decrement('loyalty_points', -$change);
    }
}
