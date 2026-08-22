<?php

namespace App\Services\Store;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Whether the store is taking orders right now, and for which fulfilment type.
 *
 * Three independent gates, deliberately kept separate so the customer can be
 * told which one actually stopped them:
 *
 *   1. Operating hours (BR-15) - 7:00 AM to 8:00 PM by default, uniform across
 *      days (PRD A-04), configurable by the Store Manager.
 *   2. An ordering override (UC-OPS-009) - a manual switch that beats the
 *      clock in both directions. "Overriding scheduled hours" has to mean
 *      opening late as well as closing early, otherwise an unplanned closure
 *      is expressible but an unplanned extension is not.
 *   3. Delivery availability (UC-OPS-010) - delivery can be suspended on its
 *      own while pickup carries on, which is what happens when the one staff
 *      member who drives is out.
 *
 * Everything here is evaluated in the store's timezone. The application clock
 * is UTC, so "is it 7am yet" is not a question the default clock can answer.
 */
class StoreAvailability
{
    public const OVERRIDE_AUTO = 'auto';

    public const OVERRIDE_OPEN = 'open';

    public const OVERRIDE_CLOSED = 'closed';

    public const TYPE_PICKUP = 'pickup';

    public const TYPE_DELIVERY = 'delivery';

    /** @return array<int, string> */
    public static function overrides(): array
    {
        return [self::OVERRIDE_AUTO, self::OVERRIDE_OPEN, self::OVERRIDE_CLOSED];
    }

    public function timezone(): string
    {
        return (string) config('store.timezone', 'Asia/Manila');
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone());
    }

    public function opensAt(): string
    {
        return $this->normalizeTime(
            Setting::get(Setting::STORE_OPENS_AT) ?? (string) config('store.hours.opens_at', '07:00'),
            '07:00'
        );
    }

    public function closesAt(): string
    {
        return $this->normalizeTime(
            Setting::get(Setting::STORE_CLOSES_AT) ?? (string) config('store.hours.closes_at', '20:00'),
            '20:00'
        );
    }

    public function override(): string
    {
        $value = Setting::get(Setting::STORE_ORDERING_OVERRIDE, self::OVERRIDE_AUTO);

        return in_array($value, self::overrides(), true) ? $value : self::OVERRIDE_AUTO;
    }

    public function deliveryEnabled(): bool
    {
        return Setting::boolean(Setting::STORE_DELIVERY_ENABLED, true);
    }

    /** Is the wall clock inside the configured window? Ignores the override. */
    public function isWithinHours(?CarbonInterface $at = null): bool
    {
        $at = $at ? CarbonImmutable::parse($at)->setTimezone($this->timezone()) : $this->now();

        $opens = $this->at($this->opensAt(), $at);
        $closes = $this->at($this->closesAt(), $at);

        // A window that ends before it starts has crossed midnight. Not the
        // configured default, but nothing stops a manager entering it.
        if ($closes->lessThanOrEqualTo($opens)) {
            return $at->greaterThanOrEqualTo($opens) || $at->lessThan($closes);
        }

        return $at->greaterThanOrEqualTo($opens) && $at->lessThan($closes);
    }

    /** The single question the checkout gate asks. */
    public function acceptsOrders(): bool
    {
        return match ($this->override()) {
            self::OVERRIDE_OPEN => true,
            self::OVERRIDE_CLOSED => false,
            default => $this->isWithinHours(),
        };
    }

    public function acceptsDelivery(): bool
    {
        return $this->acceptsOrders() && $this->deliveryEnabled();
    }

    public function accepts(string $type): bool
    {
        return $type === self::TYPE_DELIVERY ? $this->acceptsDelivery() : $this->acceptsOrders();
    }

    /**
     * Why a fulfilment type is unavailable, or null when it is available.
     *
     * @return array{code: string, message: string}|null
     */
    public function blocker(string $type): ?array
    {
        if (! $this->acceptsOrders()) {
            $reopens = $this->nextOpensAt();

            return [
                'code' => $this->override() === self::OVERRIDE_CLOSED
                    ? 'STORE_CLOSED_MANUALLY'
                    : 'STORE_CLOSED',
                'message' => $this->override() === self::OVERRIDE_CLOSED
                    ? 'Online ordering is paused right now. Please try again shortly.'
                    : 'The store is closed. We take orders from '
                        .$this->humanTime($this->opensAt()).' to '.$this->humanTime($this->closesAt())
                        .($reopens ? ', so ordering opens again '.$this->relative($reopens).'.' : '.'),
            ];
        }

        if ($type === self::TYPE_DELIVERY && ! $this->deliveryEnabled()) {
            return [
                'code' => 'DELIVERY_UNAVAILABLE',
                'message' => 'Delivery is paused right now. You can still place a pickup order.',
            ];
        }

        return null;
    }

    /**
     * When ordering next opens, or null if it is open now.
     *
     * Only meaningful while the override is 'auto' - a manual closure has no
     * scheduled end, and guessing one would be a promise the store did not
     * make.
     */
    public function nextOpensAt(): ?CarbonImmutable
    {
        if ($this->override() !== self::OVERRIDE_AUTO || $this->acceptsOrders()) {
            return null;
        }

        $now = $this->now();
        $todaysOpening = $this->at($this->opensAt(), $now);

        return $todaysOpening->greaterThan($now) ? $todaysOpening : $todaysOpening->addDay();
    }

    /**
     * The window a same-day pickup time must fall inside.
     *
     * Opens at the later of "now plus the kitchen's lead time" and today's
     * opening, and closes a buffer before the store does, so the last slot is
     * not one nobody is there to serve.
     *
     * @return array{earliest: CarbonImmutable, latest: CarbonImmutable, available: bool}
     */
    public function pickupWindow(): array
    {
        $now = $this->now();
        $lead = (int) config('store.pickup.lead_minutes', 20);
        $buffer = (int) config('store.pickup.last_order_buffer_minutes', 15);

        $earliest = $now->addMinutes($lead);
        $opening = $this->at($this->opensAt(), $now);

        if ($opening->greaterThan($earliest)) {
            $earliest = $opening;
        }

        $closing = $this->at($this->closesAt(), $now);

        if ($closing->lessThanOrEqualTo($this->at($this->opensAt(), $now))) {
            $closing = $closing->addDay();
        }

        $latest = $closing->subMinutes($buffer);

        return [
            'earliest' => $earliest,
            'latest' => $latest,
            'available' => $earliest->lessThanOrEqualTo($latest),
        ];
    }

    /**
     * Everything the client needs to render store state in one payload.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $window = $this->pickupWindow();
        $acceptsOrders = $this->acceptsOrders();

        return [
            'timezone' => $this->timezone(),
            'server_time' => $this->now()->toIso8601String(),
            'opens_at' => $this->opensAt(),
            'closes_at' => $this->closesAt(),
            'within_hours' => $this->isWithinHours(),
            'ordering_override' => $this->override(),
            'accepting_orders' => $acceptsOrders,
            'delivery_enabled' => $this->deliveryEnabled(),
            'accepting_delivery' => $this->acceptsDelivery(),
            'accepting_pickup' => $acceptsOrders,
            'next_opens_at' => $this->nextOpensAt()?->toIso8601String(),
            'pickup_window' => [
                'earliest' => $window['earliest']->toIso8601String(),
                'latest' => $window['latest']->toIso8601String(),
                'available' => $window['available'] && $acceptsOrders,
                'lead_minutes' => (int) config('store.pickup.lead_minutes', 20),
            ],
            'blockers' => [
                self::TYPE_PICKUP => $this->blocker(self::TYPE_PICKUP),
                self::TYPE_DELIVERY => $this->blocker(self::TYPE_DELIVERY),
            ],
        ];
    }

    private function at(string $time, CarbonInterface $onDay): CarbonImmutable
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return CarbonImmutable::parse($onDay)
            ->setTimezone($this->timezone())
            ->setTime((int) $hour, (int) $minute);
    }

    /** Accepts "7:00", "07:00", "07:00:00"; falls back rather than throwing. */
    private function normalizeTime(string $value, string $fallback): string
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', trim($value), $matches)) {
            return $fallback;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];

        if ($hour > 23 || $minute > 59) {
            return $fallback;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    private function humanTime(string $time): string
    {
        return $this->at($time, $this->now())->format('g:i A');
    }

    private function relative(CarbonImmutable $moment): string
    {
        return $moment->isSameDay($this->now())
            ? 'at '.$moment->format('g:i A')
            : 'tomorrow at '.$moment->format('g:i A');
    }
}
