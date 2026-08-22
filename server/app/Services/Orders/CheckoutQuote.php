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

/**
 * One authoritative answer to "can this order be placed, and for how much".
 *
 * Both the checkout screen and place-order run through here, which is the
 * point: the figures the customer agrees to and the figures the order is
 * written with come from the same code, so they cannot disagree. The client
 * sends a *selection* and a *destination*, never a price.
 *
 * Every gate is reported rather than thrown, so the UI can show all of them at
 * once - a closed store and an out-of-area pin are two separate things to fix,
 * and discovering them one refresh at a time is miserable.
 */
class CheckoutQuote
{
    public function __construct(
        private DeliveryQuote $delivery,
        private StoreAvailability $store,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
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

        $discount = $this->discount($user, $subtotal);

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
            // Statutory discount applies to food only, never the delivery fee
            // (BR-10 / FR-05.4), so it is subtracted from the subtotal before
            // the fee is added rather than from the grand total.
            'total' => round(max(0, $subtotal - (float) $discount['amount']) + $deliveryFee, 2),
            'blockers' => $blockers,
            'can_place' => $blockers === [],
        ];
    }

    /**
     * The selected cart lines, priced live.
     *
     * A null selection means the whole cart. Ids that are not in this user's
     * cart are silently dropped rather than 403'd - the usual cause is a line
     * removed in another tab, and the empty-selection blocker already covers
     * the case where nothing survives.
     *
     * Prices are read from the catalogue every time, never from a stashed
     * figure: carts float to the current price (BR-18 / FR-02.5).
     *
     * @return Collection<int, array<string, mixed>>
     */
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

    /**
     * Where the order is going, from whichever source the customer used.
     *
     * A saved address wins over loose coordinates when both arrive: picking a
     * saved address is an explicit choice, while stale coordinates in the body
     * are usually just the previous pin the form has not cleared yet. Saved
     * addresses are looked up through the user's own relation, so an id
     * belonging to someone else resolves to nothing rather than to their home.
     *
     * @return array<string, mixed>
     */
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

    /**
     * @return array{0: array<string, mixed>|null, 1: float, 2: array<string, string>|null}
     */
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
                'code' => 'OUTSIDE_SERVICE_AREA',
                'message' => $quote['message'],
            ]];
        }

        return [$quote, (float) $quote['fee'], null];
    }

    /**
     * @return array{0: array<string, mixed>|null, 1: array<string, string>|null}
     */
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

    /**
     * Who the store calls when the food is ready.
     *
     * Resolved, not stored: what the customer typed on this screen, falling
     * back to the account. An authenticated order already reaches both through
     * `user_id`, so there is nothing here worth a column of its own - and a
     * per-order contact that drifts from the profile is a second copy of the
     * same fact.
     *
     * @return array{name: string, phone: string, blocker: array<string, string>|null}
     */
    private function contact(User $user, string $type, array $input): array
    {
        $typed = trim((string) ($input['contact_name'] ?? ''));
        $fromAccount = trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        $name = $typed !== '' ? $typed : $fromAccount;

        /*
         * The account is a fallback for a *blank* field, not a repair for a
         * wrong one. Someone who typed a number meant to be reached on it -
         * quietly substituting their profile number would send the rider to
         * ring the wrong phone, and they would never find out why.
         */
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

    /**
     * A Philippine mobile number, or null if it is not one.
     *
     * Accepts the three ways people type the same number - 09XXXXXXXXX,
     * +639XXXXXXXXX, 639XXXXXXXXX - with spaces or dashes anywhere, and
     * normalises to the 09 form the store actually dials.
     */
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

    /**
     * Statutory discount standing for this account.
     *
     * Reported, not applied. Claiming it is the customer's explicit act in the
     * next checkout step (BR-09 is once per day, so it must not be spent
     * silently). This block tells that step what it is allowed to offer, and
     * lets the summary render the row without guessing the rate.
     *
     * @return array<string, mixed>
     */
    private function discount(User $user, float $subtotal): array
    {
        $claim = Discount::activeFor((int) $user->getKey());
        $approved = $claim?->isApproved() ?? false;

        $percentage = Discount::currentPercentage();

        return [
            'eligible' => $approved,
            'type' => $approved ? $claim->discount_type : null,
            'type_label' => $approved ? $claim->typeLabel() : null,
            'percentage' => $percentage,
            'used_today' => $approved ? $this->usedToday($user) : false,
            // What claiming it would be worth, so the next step can show the
            // figure before the customer commits to spending the day's use.
            'available_amount' => $approved ? round($subtotal * ($percentage / 100), 2) : 0.0,
            'applied' => false,
            'amount' => 0.0,
        ];
    }

    /**
     * BR-09: once per calendar day, Asia/Manila, regardless of how that order
     * ended - a cancelled discounted order still spends the day.
     */
    private function usedToday(User $user): bool
    {
        $today = CarbonImmutable::now(Discount::USAGE_TIMEZONE);

        return $user->orders()
            ->where('discount_amount', '>', 0)
            ->whereBetween('created_at', [
                $today->startOfDay()->utc(),
                $today->endOfDay()->utc(),
            ])
            ->exists();
    }
}
