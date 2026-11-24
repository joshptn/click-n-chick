<?php

namespace App\Services\Orders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Discount;
use App\Models\User;
use App\Services\Catalog\AddonPicks;
use App\Services\Loyalty\LoyaltyPoints;
use App\Services\Loyalty\LoyaltyRedemption;
use Illuminate\Support\Collection;

class AdvanceQuote
{
    public function __construct(
        private AdvanceSchedule $schedule,
        private DiscountUsage $discountUsage,
        private LoyaltyPoints $points,
        private LoyaltyRedemption $redemption,
    ) {}

    public function build(User $user, array $input): array
    {
        $lines = $this->lines($user, $input['cart_item_ids'] ?? null);
        $blockers = [];

        if ($lines->isEmpty()) {
            $blockers[] = [
                'code' => 'EMPTY_SELECTION',
                'message' => 'Choose at least one item before scheduling your order.',
            ];
        }

        $subtotal = round((float) $lines->sum('subtotal'), 2);

        // Dishes to pay with points (decided 2026-11-24). Checked against the
        // balance now; the points are taken when the order is paid, at that
        // day's prices (AdvanceRepricing, OrderPaymentController).
        $redemption = $this->redemption->apply($user, $lines, $input['redeem'] ?? []);
        $lines = $redemption['lines'];

        if ($redemption['blocker']) {
            $blockers[] = $redemption['blocker'];
        }

        [$collectAt, $scheduleBlocker] = $this->schedule->evaluate($input['scheduled_for'] ?? null);

        if ($scheduleBlocker) {
            $blockers[] = $scheduleBlocker;
        }

        $contact = $this->contact($user);

        if ($contact['blocker']) {
            $blockers[] = $contact['blocker'];
        }

        // One unit of the highest-priced dish marked for it (FR-05.4), never a
        // unit paid with points.
        $discountLine = StatutoryDiscount::pick(CheckoutQuote::paidLines($lines));
        $discountBase = (float) ($discountLine['base_price'] ?? 0);

        $discount = $this->discount(
            $user,
            $discountLine,
            (bool) ($input['apply_discount'] ?? false),
            $scheduleBlocker === null ? $collectAt : null,
        );

        if ($discount['blocker']) {
            $blockers[] = $discount['blocker'];
        }

        unset($discount['blocker']);

        return [
            'fulfilment_type' => 'pickup',
            'schedule' => array_merge($this->schedule->snapshot(), [
                'requested_at' => $collectAt?->toIso8601String(),
            ]),
            'items' => $lines->values()->all(),
            'item_count' => (int) $lines->sum('quantity'),
            'subtotal' => $subtotal,
            'discount_base' => $discountBase,
            'contact' => ['name' => $contact['name'], 'phone' => $contact['phone']],
            'discount' => $discount,
            'points_amount' => $redemption['amount'],
            'loyalty' => [
                'balance' => round((float) $user->loyalty_points, 2),
                'earn_rate' => $this->points->earnRate(),
                'peso_per_point' => $this->points->pesoPerPoint(),
                'redeemable' => $this->points->redeemable(),
                'points_used' => $redemption['points'],
                'amount' => $redemption['amount'],
                'points_to_earn' => $this->points->earnedOn(round($subtotal - $redemption['amount'], 2)),
            ],
            'total' => round(max(0, $subtotal - (float) $discount['amount'] - $redemption['amount']), 2),
            'blockers' => $blockers,
            'can_submit' => $blockers === [],
        ];
    }

    public function lines(User $user, ?array $ids): Collection
    {
        $cart = Cart::query()
            ->where('user_id', $user->getKey())
            ->where('cart_status', Cart::STATUS_ADVANCE)
            ->first();

        if ($cart === null) {
            return collect();
        }

        $query = $cart->items()->with(['food', 'selectedAddons.sizes']);

        if ($ids !== null) {
            $clean = collect($ids)->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->all();

            if ($clean === []) {
                return collect();
            }

            $query->whereIn('id', $clean);
        }

        return $query->orderByDesc('id')->get()
            ->filter(fn (CartItem $item) => $item->food !== null)
            ->map(function (CartItem $item) {
                $food = $item->food;
                // Each in the size picked; today's switches do not apply to another day.
                $addons = $item->selectedAddons->map(fn ($addon) => AddonPicks::describe($addon))->values();

                $addonTotal = (float) $addons->sum('addon_price');
                $unitPrice = (float) $food->price + $addonTotal;

                return [
                    'id' => $item->id,
                    'food_id' => $item->food_id,
                    'food_name' => $food->food_name,
                    'thumbnail' => $food->thumbnail,
                    'quantity' => $item->quantity,
                    'base_price' => (float) $food->price,
                    'is_discountable' => (bool) $food->is_discountable,
                    'addons' => $addons->all(),
                    'addons_total' => $addonTotal,
                    'unit_price' => $unitPrice,
                    'subtotal' => round($unitPrice * $item->quantity, 2),
                ];
            });
    }

    private function contact(User $user): array
    {
        $name = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));
        $phone = $this->normalizePhone($user->phone_number) ?? '';

        $blocker = match (true) {
            $name === '' => [
                'code' => 'CONTACT_NAME_REQUIRED',
                'message' => 'Add your name to your profile before scheduling an order.',
            ],
            $phone === '' => [
                'code' => 'CONTACT_PHONE_INVALID',
                'message' => 'Add a mobile number to your profile so the store can reach you.',
            ],
            default => null,
        };

        return ['name' => $name, 'phone' => $phone, 'blocker' => $blocker];
    }

    private function normalizePhone(mixed $raw): ?string
    {
        $digits = preg_replace('/[\s\-()]/', '', (string) $raw);

        if ($digits === '' || $digits === null) {
            return null;
        }

        if (preg_match('/^\+?63(9\d{9})$/', $digits, $matches)) {
            return '0'.$matches[1];
        }

        return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
    }

    private function discount(User $user, ?array $line, bool $requested, ?\Carbon\CarbonImmutable $collectAt): array
    {
        $latest = $user->latestDiscountClaim()->first();
        $approved = $latest?->isApproved() ?? false;

        $percentage = Discount::currentPercentage();
        $base = (float) ($line['base_price'] ?? 0);
        $saving = $approved ? StatutoryDiscount::for($base, $percentage) : StatutoryDiscount::for(0, $percentage);
        $available = $saving['total'];

        $usedOnDate = $approved && $collectAt !== null && $this->discountUsage->usedOn($user, $collectAt);

        $status = match (true) {
            $approved => 'approved',
            $latest?->isPending() ?? false => 'pending',
            $latest?->isRejected() ?? false => 'rejected',
            default => 'none',
        };

        $canApply = $approved && ! $usedOnDate && $available > 0;
        $applied = $requested && $canApply;

        $blocker = null;

        if ($requested && ! $canApply) {
            $blocker = match (true) {
                $usedOnDate => [
                    'code' => 'DISCOUNT_ALREADY_USED',
                    'message' => 'You already have a discounted order for that date. Pick another date, or continue without the discount.',
                ],
                $approved && $line === null => [
                    'code' => 'DISCOUNT_NO_ELIGIBLE_ITEM',
                    'message' => 'None of the items in your order take the Senior/PWD discount. Continue without it.',
                ],
                default => [
                    'code' => 'DISCOUNT_NOT_ELIGIBLE',
                    'message' => 'Your Senior Citizen or PWD discount has not been approved yet.',
                ],
            };
        }

        return [
            'eligible' => $approved,
            'status' => $status,
            'type' => $approved ? $latest->discount_type : null,
            'type_label' => $approved ? $latest->typeLabel() : null,
            'percentage' => $percentage,
            'used_today' => $usedOnDate,
            'can_apply' => $canApply,
            'vat_rate' => Discount::VAT_RATE,
            // What using it would take off: the VAT first, then the rate.
            'available_amount' => $available,
            'vat_exempt_amount' => $saving['vat_exempt'],
            'percentage_amount' => $saving['discount'],
            // The one dish it goes on, or null when none in the order is marked.
            'item' => StatutoryDiscount::describe($line),
            'applied' => $applied,
            // Everything taken off, VAT exemption included (orders.discount_amount).
            'amount' => $applied ? $available : 0.0,
            'blocker' => $blocker,
        ];
    }
}
