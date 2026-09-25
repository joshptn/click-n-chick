<?php

namespace App\Services\Orders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Discount;
use App\Models\User;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

class CheckoutQuote
{
    public function __construct(
        private DeliveryQuote $delivery,
        private StoreAvailability $store,
        private CancellationPolicy $cancellation,
        private DiscountUsage $discountUsage,
    ) {}

    public function build(User $user, array $input): array
    {
        $type = in_array($input['fulfilment_type'] ?? null, [StoreAvailability::TYPE_PICKUP, StoreAvailability::TYPE_DELIVERY], true)
            ? $input['fulfilment_type']
            : StoreAvailability::TYPE_PICKUP;

        $lines = $this->lines($user, $input['cart_item_ids'] ?? null);
        $blockers = [];

        if ($storeBlocker = $this->store->blocker($type)) {
            $blockers[] = $storeBlocker;
        }

        if ($lines->isEmpty()) {
            $blockers[] = [
                'code' => 'EMPTY_SELECTION',
                'message' => 'Choose at least one item from your cart before checking out.',
            ];
        }

        $unavailable = $lines->reject(fn (array $line) => $line['is_orderable']);

        if ($unavailable->isNotEmpty()) {
            $blockers[] = [
                'code' => 'ITEM_UNAVAILABLE',
                'message' => $unavailable->count() === 1
                    ? $unavailable->first()['food_name'].' just sold out. Remove it to continue.'
                    : $unavailable->count().' of your items just sold out. Remove them to continue.',
            ];
        }

        $subtotal = round((float) $lines->sum('subtotal'), 2);

        $destination = $this->destination($user, $input);

        $delivery = null;
        $deliveryFee = 0.0;

        if ($type === StoreAvailability::TYPE_DELIVERY) {
            [$delivery, $deliveryFee, $deliveryBlocker] = $this->deliveryLeg($destination);

            if ($deliveryBlocker) {
                $blockers[] = $deliveryBlocker;
            }
        }

        $pickup = null;

        if ($type === StoreAvailability::TYPE_PICKUP) {
            [$pickup, $pickupBlocker] = $this->pickupLeg($input);

            if ($pickupBlocker) {
                $blockers[] = $pickupBlocker;
            }
        }

        $contact = $this->contact($user, $type, $input);

        if ($contact['blocker']) {
            $blockers[] = $contact['blocker'];
        }

        $discount = $this->discount($user, $subtotal, (bool) ($input['apply_discount'] ?? false));

        if ($discount['blocker']) {
            $blockers[] = $discount['blocker'];
        }

        unset($discount['blocker']);

        return [
            'fulfilment_type' => $type,
            'store' => $this->store->snapshot(),
            'items' => $lines->values()->all(),
            'item_count' => (int) $lines->sum('quantity'),
            'subtotal' => $subtotal,
            'destination' => $destination,
            'contact' => ['name' => $contact['name'], 'phone' => $contact['phone']],
            'delivery' => $delivery,
            'delivery_fee' => round($deliveryFee, 2),
            'pickup' => $pickup,
            'discount' => $discount,
            'cancellation' => $this->cancellationTerms(),
            'total' => round(max(0, $subtotal - (float) $discount['amount']) + $deliveryFee, 2),
            'blockers' => $blockers,
            'can_place' => $blockers === [],
        ];
    }

    /** @return array<string, mixed> */
    private function cancellationTerms(): array
    {
        $through = $this->cancellation->fullRefundThrough();

        return [
            'full_refund_through' => $through,
            'full_refund_through_label' => OrderStatus::label($through),
            'advance_cutoff_hours' => $this->cancellation->advanceCutoffHours(),
        ];
    }

    public function lines(User $user, ?array $ids): Collection
    {
        $cart = Cart::query()
            ->where('user_id', $user->getKey())
            ->where('cart_status', 'active')
            ->first();

        if ($cart === null) {
            return collect();
        }

        $query = $cart->items()->with(['food', 'selectedAddons']);

        if ($ids !== null) {
            $clean = collect($ids)->filter(fn ($id) => is_numeric($id))->map(fn ($id) => (int) $id)->all();

            if ($clean === []) {
                return collect();
            }

            $query->whereIn('id', $clean);
        }

        return $query->orderByDesc('id')->get()->map(function (CartItem $item) {
            $food = $item->food;
            $addons = $item->selectedAddons;

            $addonTotal = (float) $addons->sum('addon_price');
            $unitPrice = (float) ($food->price ?? 0) + $addonTotal;

            return [
                'id' => $item->id,
                'food_id' => $item->food_id,
                'food_name' => $food->food_name ?? 'Unavailable item',
                'thumbnail' => $food->thumbnail ?? null,
                'quantity' => $item->quantity,
                'base_price' => (float) ($food->price ?? 0),
                'addons' => $addons->map(fn ($addon) => [
                    'id' => $addon->id,
                    'addon_name' => $addon->addon_name,
                    'addon_price' => (float) $addon->addon_price,
                ])->values()->all(),
                'addons_total' => $addonTotal,
                'unit_price' => $unitPrice,
                'subtotal' => round($unitPrice * $item->quantity, 2),
                'is_orderable' => $food?->is_orderable ?? false,
            ];
        });
    }

    private function destination(User $user, array $input): array
    {
        $addressId = $input['address_id'] ?? null;

        if ($addressId !== null) {
            $address = $user->addresses()->find($addressId);

            if ($address !== null && $address->latitude !== null && $address->longitude !== null) {
                return [
                    'source' => 'saved_address',
                    'address_id' => (int) $address->getKey(),
                    'latitude' => (float) $address->latitude,
                    'longitude' => (float) $address->longitude,
                    'full_address' => (string) $address->full_address,
                    'label' => $address->label,
                    'locality' => $address->location,
                    'delivery_note' => $address->delivery_note,
                ];
            }
        }

        $latitude = $input['latitude'] ?? null;
        $longitude = $input['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return ['source' => 'none', 'address_id' => null, 'latitude' => null, 'longitude' => null];
        }

        return [
            'source' => 'pinned',
            'address_id' => null,
            'latitude' => (float) $latitude,
            'longitude' => (float) $longitude,
            'full_address' => trim((string) ($input['full_address'] ?? '')),
            'label' => null,
            'locality' => $input['location'] ?? null,
            'delivery_note' => $input['delivery_note'] ?? null,
        ];
    }

    private function deliveryLeg(array $destination): array
    {
        $latitude = $destination['latitude'] ?? null;
        $longitude = $destination['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return [null, 0.0, [
                'code' => 'LOCATION_REQUIRED',
                'message' => 'Set your delivery location to see the delivery fee.',
            ]];
        }

        $quote = $this->delivery->for((float) $latitude, (float) $longitude);

        if (! $quote['within_service_area']) {
            return [$quote, 0.0, [
                'code' => match (true) {
                    // No road to that spot: fixable by moving the pin.
                    ($quote['route_found'] ?? null) === false => 'NO_ROUTE_FOUND',
                    // The engine could not be reached: fixable by waiting.
                    ! $quote['routing_available'] => 'ROUTING_UNAVAILABLE',
                    // Genuinely too far: not fixable, switch to pickup.
                    default => 'OUTSIDE_SERVICE_AREA',
                },
                'message' => $quote['message'],
            ]];
        }

        return [$quote, (float) $quote['fee'], null];
    }

    private function pickupLeg(array $input): array
    {
        $window = $this->store->pickupWindow();

        $payload = [
            'earliest' => $window['earliest']->toIso8601String(),
            'latest' => $window['latest']->toIso8601String(),
            'requested_at' => null,
        ];

        $raw = $input['pickup_at'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return [$payload, [
                'code' => 'PICKUP_TIME_REQUIRED',
                'message' => 'Tell us what time you will collect your order.',
            ]];
        }

        try {
            $requested = CarbonImmutable::parse($raw)->setTimezone($this->store->timezone());
        } catch (Throwable) {
            return [$payload, [
                'code' => 'PICKUP_TIME_INVALID',
                'message' => 'That pickup time could not be read. Please choose it again.',
            ]];
        }

        $payload['requested_at'] = $requested->toIso8601String();

        if ($requested->lessThan($window['earliest'])) {
            return [$payload, [
                'code' => 'PICKUP_TIME_TOO_SOON',
                'message' => 'We need until '.$window['earliest']->format('g:i A')
                    .' to have your order ready. Please choose a later time.',
            ]];
        }

        if ($requested->greaterThan($window['latest'])) {
            return [$payload, [
                'code' => 'PICKUP_TIME_TOO_LATE',
                'message' => 'The last pickup today is '.$window['latest']->format('g:i A').'.',
            ]];
        }

        return [$payload, null];
    }

    private function contact(User $user, string $type, array $input): array
    {
        $typed = trim((string) ($input['contact_name'] ?? ''));
        $fromAccount = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        $name = $typed !== '' ? $typed : $fromAccount;
        $typedPhone = trim((string) ($input['contact_phone'] ?? ''));

        $phone = $typedPhone !== ''
            ? ($this->normalizePhone($typedPhone) ?? '')
            : ($this->normalizePhone($user->phone_number ?? null) ?? '');

        $blocker = null;

        if (trim($name) === '') {
            $blocker = [
                'code' => 'CONTACT_NAME_REQUIRED',
                'message' => $type === StoreAvailability::TYPE_DELIVERY
                    ? 'Enter the name of whoever will receive the order.'
                    : 'Enter the name of whoever will collect the order.',
            ];
        } elseif ($phone === '') {
            $blocker = [
                'code' => 'CONTACT_PHONE_INVALID',
                'message' => 'Enter an 11-digit mobile number, starting 09.',
            ];
        }

        return ['name' => trim($name), 'phone' => $phone, 'blocker' => $blocker];
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

    private function discount(User $user, float $subtotal, bool $requested): array
    {
        $latest = $user->latestDiscountClaim()->first();
        $approved = $latest?->isApproved() ?? false;

        $percentage = Discount::currentPercentage();
        $usedToday = $approved && $this->usedToday($user);
        $available = $approved ? round($subtotal * ($percentage / 100), 2) : 0.0;

        $status = match (true) {
            $approved => 'approved',
            $latest?->isPending() ?? false => 'pending',
            $latest?->isRejected() ?? false => 'rejected',
            default => 'none',
        };

        $canApply = $approved && ! $usedToday && $available > 0;
        $applied = $requested && $canApply;

        $blocker = null;

        if ($requested && ! $canApply) {
            $blocker = $usedToday
                ? [
                    'code' => 'DISCOUNT_ALREADY_USED',
                    'message' => 'You have already used your discount today. It resets tomorrow.',
                ]
                : [
                    'code' => 'DISCOUNT_NOT_ELIGIBLE',
                    'message' => 'Your Senior Citizen or PWD discount has not been approved yet.',
                ];
        }

        return [
            'eligible' => $approved,
            'status' => $status,
            'type' => $approved ? $latest->discount_type : null,
            'type_label' => $approved ? $latest->typeLabel() : null,
            'percentage' => $percentage,
            'used_today' => $usedToday,
            'can_apply' => $canApply,
            'available_amount' => $available,
            'applied' => $applied,
            'amount' => $applied ? $available : 0.0,
            'blocker' => $blocker,
        ];
    }

    private function usedToday(User $user): bool
    {
        return $this->discountUsage->usedToday($user);
    }
}
