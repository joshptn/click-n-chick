<?php

namespace App\Http\Controllers;

use App\Http\Resources\OrderTrackingResource;
use App\Models\Order;
use App\Services\Orders\AmendmentPolicy;
use App\Services\Orders\DeliveryQuote;
use App\Services\Orders\OrderAnnouncer;
use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Throwable;

class OrderModificationController extends Controller
{
    use AuthorizesRequests;

    private const WITH = ['items.food', 'items.addons', 'user'];

    public function __construct(
        private AmendmentPolicy $amendment,
        private DeliveryQuote $delivery,
        private StoreAvailability $store,
        private OrderAnnouncer $announcer,
    ) {}

    public function update(Request $request, Order $order)
    {
        $this->authorize('amend', $order);

        $validated = $request->validate([
            'address_id' => ['sometimes', 'nullable', 'integer'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'full_address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'delivery_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pickup_at' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        if ($validated === []) {
            return $this->refuse('There was nothing to change.', 'NOTHING_TO_CHANGE');
        }

        $changes = [];

        if ($this->touchesAddress($validated)) {
            $result = $this->resolveAddress($order, $validated);

            if (isset($result['error'])) {
                return $result['error'];
            }

            $changes += $result['changes'];
        }

        if (array_key_exists('delivery_note', $validated)) {
            if (! $this->amendment->canEditNote($order)) {
                return $this->refuse(
                    'Your order has already left the kitchen, so the delivery note can no longer be changed.',
                    'NOTE_LOCKED'
                );
            }

            $note = trim((string) $validated['delivery_note']);
            $changes['delivery_note'] = $note === '' ? null : $note;
        }

        if (array_key_exists('pickup_at', $validated)) {
            $result = $this->resolvePickupTime($order, $validated['pickup_at']);

            if (isset($result['error'])) {
                return $result['error'];
            }

            $changes += $result['changes'];
        }

        if ($changes === []) {
            return $this->refuse('There was nothing to change.', 'NOTHING_TO_CHANGE');
        }

        $changes['details_confirmed_at'] = null;

        $order->forceFill($changes)->save();

        $this->announcer->announce($order, 'update');

        return response()->json([
            'message' => 'Your order has been updated.',
            'order' => new OrderTrackingResource($order->load(self::WITH)),
        ]);
    }

    /** @param array<string, mixed> $input */
    private function touchesAddress(array $input): bool
    {
        return array_key_exists('address_id', $input)
            || array_key_exists('latitude', $input)
            || array_key_exists('longitude', $input)
            || array_key_exists('full_address', $input)
            || array_key_exists('location', $input);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{changes?: array<string, mixed>, error?: \Illuminate\Http\JsonResponse}
     */
    private function resolveAddress(Order $order, array $input): array
    {
        if (! $this->amendment->canEditAddress($order)) {
            return ['error' => $this->refuse(
                $order->order_type === StoreAvailability::TYPE_DELIVERY
                    ? 'Your order is already on its way, so the address can no longer be changed.'
                    : 'This is a pickup order, so it has no delivery address.',
                'ADDRESS_LOCKED'
            )];
        }

        $destination = $this->destination($order, $input);

        if ($destination === null) {
            return ['error' => $this->refuse(
                'That address could not be read. Please pick it on the map again.',
                'LOCATION_REQUIRED'
            )];
        }

        $quote = $this->delivery->for($destination['latitude'], $destination['longitude']);

        if (! $quote['within_service_area']) {
            return ['error' => $this->refuse($quote['message'], 'OUTSIDE_SERVICE_AREA')];
        }

        // The fee is the gate. Moving it in either direction means money has to
        // change hands again, which nothing can do yet.
        $current = round((float) $order->delivery_fee, 2);
        $next = round((float) $quote['fee'], 2);

        if ($current !== $next) {
            return ['error' => $this->refuse(
                'Delivery to that address costs a different amount (P'.number_format($next, 2)
                    .' instead of P'.number_format($current, 2).'). Please call the store and we will sort it out.',
                'FEE_WOULD_CHANGE',
                ['current_fee' => $current, 'new_fee' => $next]
            )];
        }

        return ['changes' => [
            'address_id' => $destination['address_id'],
            'latitude' => $destination['latitude'],
            'longitude' => $destination['longitude'],
            'full_address' => $destination['full_address'],
            'location' => $destination['location'],
            'delivery_distance_km' => $quote['distance_km'],
        ]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    private function destination(Order $order, array $input): ?array
    {
        $addressId = $input['address_id'] ?? null;

        if ($addressId !== null && $order->user !== null) {
            $address = $order->user->addresses()->find($addressId);

            if ($address === null || $address->latitude === null || $address->longitude === null) {
                return null;
            }

            return [
                'address_id' => (int) $address->getKey(),
                'latitude' => (float) $address->latitude,
                'longitude' => (float) $address->longitude,
                'full_address' => (string) $address->full_address,
                'location' => $address->location,
            ];
        }

        if (! isset($input['latitude'], $input['longitude'])) {
            return null;
        }

        return [
            'address_id' => null,
            'latitude' => (float) $input['latitude'],
            'longitude' => (float) $input['longitude'],
            'full_address' => trim((string) ($input['full_address'] ?? '')) ?: $order->full_address,
            'location' => $input['location'] ?? $order->location,
        ];
    }

    /** @return array{changes?: array<string, mixed>, error?: \Illuminate\Http\JsonResponse} */
    private function resolvePickupTime(Order $order, mixed $raw): array
    {
        if (! $this->amendment->canEditPickupTime($order)) {
            return ['error' => $this->refuse(
                $order->order_type === StoreAvailability::TYPE_DELIVERY
                    ? 'This is a delivery order, so it has no collection time.'
                    : 'Your order is already waiting for you, so the collection time can no longer be changed.',
                'PICKUP_TIME_LOCKED'
            )];
        }

        if (! is_string($raw) || trim($raw) === '') {
            return ['error' => $this->refuse(
                'Tell us what time you will collect your order.',
                'PICKUP_TIME_REQUIRED'
            )];
        }

        try {
            $requested = CarbonImmutable::parse($raw)->setTimezone($this->store->timezone());
        } catch (Throwable) {
            return ['error' => $this->refuse(
                'That collection time could not be read. Please choose it again.',
                'PICKUP_TIME_INVALID'
            )];
        }

        $window = $this->store->pickupWindow();

        if ($requested->lessThan($window['earliest'])) {
            return ['error' => $this->refuse(
                'We need until '.$window['earliest']->format('g:i A')
                    .' to have your order ready. Please choose a later time.',
                'PICKUP_TIME_TOO_SOON'
            )];
        }

        if ($requested->greaterThan($window['latest'])) {
            return ['error' => $this->refuse(
                'The last collection today is '.$window['latest']->format('g:i A').'.',
                'PICKUP_TIME_TOO_LATE'
            )];
        }

        return ['changes' => ['pickup_at' => $requested->utc()]];
    }

    /** @param array<string, mixed> $extra */
    private function refuse(string $message, string $code, array $extra = [])
    {
        return response()->json([
            'message' => $message,
            'error_code' => $code,
        ] + $extra, 422);
    }
}
