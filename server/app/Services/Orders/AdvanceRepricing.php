<?php

namespace App\Services\Orders;

use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use Illuminate\Support\Facades\DB;

/**
 * An advance order is paid at the menu's prices on the day it is paid, not the
 * day it was asked for (BR-18, FR-02.6; decided 2026-11-24).
 *
 * Run when the customer opens the payment (OrderPaymentController), never
 * before: until then the order keeps the figures the Store Agent accepted.
 * Each line takes its dish's current price and its add-ons' current prices, in
 * the size picked; a dish, add-on or size no longer in the catalogue keeps the
 * price it had.
 *
 * A discount the order was placed with is worked out again on the new prices,
 * as a quote would (FR-05.4): one unit of the highest-priced marked dish, at
 * the current rate. Who may use it is not asked again; that was settled when
 * the request was sent (FR-05.8). An order placed without one stays without.
 *
 * Dishes paid with points are priced on the day too (decided 2026-11-24):
 * points_amount is their current menu price, and the discount never goes on
 * one of them. Whether the balance covers it is asked when the payment opens
 * (OrderPaymentController); if it does not, dropRedemption() charges them.
 *
 * An order with no lines, or whose figures already match, is left alone.
 */
final class AdvanceRepricing
{
    /**
     * The order at today's prices, or null when nothing would change.
     *
     * @return array{lines: list<array<string, mixed>>, subtotal: float, discount: float, vat_exempt: float, points: float, total: float}|null
     */
    public function quote(Order $order): ?array
    {
        $items = $order->items()->with(['food', 'addonSelections.addon.sizes'])->get();

        if ($items->isEmpty()) {
            return null;
        }

        $lines = $items->map(fn (OrderItem $item) => $this->line($item))->all();
        $linesMoved = collect($lines)->contains(fn (array $line) => $line['changed']);

        $subtotal = $linesMoved
            ? round(array_sum(array_column($lines, 'subtotal')), 2)
            : round((float) $order->subtotal, 2);

        [$discount, $vatExempt] = $this->discount($order, $lines);

        $points = round(array_sum(array_map(fn (array $line) => $line['dish_price'] * $line['redeemed_quantity'], $lines)), 2);

        $total = round(max(0, $subtotal - $discount - $points) + (float) $order->delivery_fee, 2);

        $same = ! $linesMoved
            && $this->equal($discount, (float) $order->discount_amount)
            && $this->equal($vatExempt, (float) $order->vat_exempt_amount)
            && $this->equal($points, (float) $order->points_amount);

        if ($same) {
            return null;
        }

        return [
            'lines' => $lines,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'vat_exempt' => $vatExempt,
            'points' => $points,
            'total' => $total,
        ];
    }

    /**
     * Writes today's prices onto an unpaid advance order, under its row lock.
     * Returns whether anything changed.
     */
    public function apply(Order $order): bool
    {
        return DB::transaction(function () use ($order) {
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null
                || ! $locked->isAdvance()
                || $locked->isPaid()
                || ! in_array((string) $locked->status, AdvanceExpiry::UNPAID_STATUSES, true)) {
                return false;
            }

            $priced = $this->quote($locked);

            if ($priced === null) {
                return false;
            }

            foreach ($priced['lines'] as $line) {
                if (! $line['changed']) {
                    continue;
                }

                OrderItem::query()->whereKey($line['id'])->update([
                    'price' => $line['unit_price'],
                    'unit_price' => $line['unit_price'],
                    'subtotal' => $line['subtotal'],
                ]);

                foreach ($line['addons'] as $addon) {
                    OrderItemAddon::query()->whereKey($addon['id'])->update(['unit_price' => $addon['unit_price']]);
                }
            }

            $locked->forceFill([
                'subtotal' => $priced['subtotal'],
                'discount_amount' => $priced['discount'],
                'vat_exempt_amount' => $priced['vat_exempt'],
                'points_amount' => $priced['points'],
                'total_amount' => $priced['total'],
                'total_price' => $priced['total'],
            ])->save();

            return true;
        });
    }

    /**
     * The customer's points no longer cover the dishes they picked to pay
     * with them (decided 2026-11-24): those dishes are charged instead, and
     * the order priced again.
     */
    public function dropRedemption(Order $order): void
    {
        DB::transaction(function () use ($order) {
            OrderItem::query()->where('order_id', $order->getKey())->update(['redeemed_quantity' => 0]);

            $this->apply($order);
        });
    }

    /** @return array<string, mixed> */
    private function line(OrderItem $item): array
    {
        $stored = round((float) ($item->unit_price ?? $item->price), 2);

        $addons = $item->addonSelections->map(fn (OrderItemAddon $selection) => [
            'id' => (int) $selection->id,
            'unit_price' => round((float) ($selection->addon?->priceFor($selection->size) ?? $selection->unit_price), 2),
        ])->all();

        $food = $item->food;
        $base = $food !== null ? (float) $food->price : null;

        $unit = $base !== null
            ? round($base + array_sum(array_column($addons, 'unit_price')), 2)
            : $stored;

        $quantity = (int) $item->quantity;
        // The dish alone, what a unit paid with points covers.
        $storedDish = max(0.0, $stored - array_sum(array_map(fn ($selection) => (float) $selection->unit_price, $item->addonSelections->all())));

        return [
            'id' => (int) $item->id,
            'food_id' => (int) $item->food_id,
            'food_name' => $food?->food_name,
            'quantity' => $quantity,
            // For StatutoryDiscount::pick: a deleted dish has no current price to discount.
            'base_price' => $base ?? 0.0,
            'is_discountable' => $food !== null && (bool) $food->is_discountable,
            'is_orderable' => true,
            'addons' => $addons,
            'unit_price' => $unit,
            'subtotal' => round($unit * $quantity, 2),
            'redeemed_quantity' => (int) $item->redeemed_quantity,
            'dish_price' => round($base ?? $storedDish, 2),
            'changed' => ! $this->equal($unit, $stored),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{0: float, 1: float} the discount and its VAT part
     */
    private function discount(Order $order, array $lines): array
    {
        if ((float) $order->discount_amount <= 0) {
            return [0.0, 0.0];
        }

        $line = StatutoryDiscount::pick(CheckoutQuote::paidLines(collect($lines)));
        $saving = StatutoryDiscount::for((float) ($line['base_price'] ?? 0), Discount::currentPercentage());

        return [$saving['total'], $saving['vat_exempt']];
    }

    private function equal(float $a, float $b): bool
    {
        return abs(round($a, 2) - round($b, 2)) < 0.005;
    }
}
