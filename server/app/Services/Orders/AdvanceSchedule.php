<?php

namespace App\Services\Orders;

use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;
use Throwable;

class AdvanceSchedule
{
    public const MAX_DAYS_AHEAD = 30;

    public function __construct(private StoreAvailability $store) {}

    public function earliestDate(): CarbonImmutable
    {
        $today = $this->store->now()->startOfDay();

        return $this->store->isWithinHours()
            ? $today->addDay()
            : $today->addDays($this->pastClosingToday() ? 2 : 1);
    }

    public function latestDate(): CarbonImmutable
    {
        return $this->store->now()->startOfDay()->addDays(self::MAX_DAYS_AHEAD);
    }

    public function windowOn(CarbonImmutable $day): array
    {
        $buffer = (int) config('store.pickup.last_order_buffer_minutes', 15);

        $opens = $this->atTime($this->store->opensAt(), $day);
        $closes = $this->atTime($this->store->closesAt(), $day);

        if ($closes->lessThanOrEqualTo($opens)) {
            $closes = $closes->addDay();
        }

        return ['earliest' => $opens, 'latest' => $closes->subMinutes($buffer)];
    }


    public function evaluate(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [null, [
                'code' => 'SCHEDULE_REQUIRED',
                'message' => 'Choose the date and time you will collect your order.',
            ]];
        }

        try {
            $requested = CarbonImmutable::parse($raw)->setTimezone($this->store->timezone());
        } catch (Throwable) {
            return [null, [
                'code' => 'SCHEDULE_INVALID',
                'message' => 'That collection date could not be read. Please choose it again.',
            ]];
        }

        $earliest = $this->earliestDate();

        if ($requested->startOfDay()->lessThan($earliest)) {
            return [$requested, [
                'code' => 'SCHEDULE_TOO_SOON',
                'message' => $this->tooSoonMessage($earliest),
            ]];
        }

        if ($requested->startOfDay()->greaterThan($this->latestDate())) {
            return [$requested, [
                'code' => 'SCHEDULE_TOO_FAR',
                'message' => 'You can book up to '.self::MAX_DAYS_AHEAD.' days ahead. Choose an earlier date.',
            ]];
        }

        $window = $this->windowOn($requested->startOfDay());

        if ($requested->lessThan($window['earliest'])) {
            return [$requested, [
                'code' => 'SCHEDULE_BEFORE_OPENING',
                'message' => 'We open at '.$window['earliest']->format('g:i A').'. Choose a later time.',
            ]];
        }

        if ($requested->greaterThan($window['latest'])) {
            return [$requested, [
                'code' => 'SCHEDULE_AFTER_CLOSING',
                'message' => 'The last collection is '.$window['latest']->format('g:i A').'. Choose an earlier time.',
            ]];
        }

        return [$requested, null];
    }

    public function snapshot(): array
    {
        $earliest = $this->earliestDate();
        $window = $this->windowOn($earliest);

        return [
            'earliest_date' => $earliest->toDateString(),
            'latest_date' => $this->latestDate()->toDateString(),
            'max_days_ahead' => self::MAX_DAYS_AHEAD,
            'opens_at' => $window['earliest']->format('H:i'),
            'closes_at' => $window['latest']->format('H:i'),
            'timezone' => $this->store->timezone(),
            'tomorrow_closed' => ! $this->store->isWithinHours() && $this->pastClosingToday(),
        ];
    }

    private function tooSoonMessage(CarbonImmutable $earliest): string
    {
        $tomorrow = $this->store->now()->startOfDay()->addDay();

        if ($earliest->greaterThan($tomorrow)) {
            return 'Ordering for tomorrow closed when the store did. The earliest we can take is '
                .$earliest->format('l, j F').'.';
        }

        return 'Advance orders start from tomorrow. For food today, order from the main menu.';
    }

    private function pastClosingToday(): bool
    {
        $now = $this->store->now();
        $opens = $this->atTime($this->store->opensAt(), $now);
        $closes = $this->atTime($this->store->closesAt(), $now);

        if ($closes->lessThanOrEqualTo($opens)) {
            return false;
        }

        return $now->greaterThanOrEqualTo($closes);
    }

    private function atTime(string $time, CarbonImmutable $onDay): CarbonImmutable
    {
        [$hour, $minute] = array_pad(explode(':', $time), 2, '0');

        return $onDay->setTimezone($this->store->timezone())->setTime((int) $hour, (int) $minute);
    }
}
