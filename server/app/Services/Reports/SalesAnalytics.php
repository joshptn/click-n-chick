<?php

namespace App\Services\Reports;

use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;

/**
 * The sales chart and top products on the Analytics Dashboard (UC-REP-001).
 *
 * The same sales as the Daily Sales Report: orders that closed as completed or
 * delivered, on the store day they closed, at their checkout totals (BR-42).
 *
 * The week comes back nested as days, hours, and quarter hours from one query,
 * so drilling into the chart asks the server for nothing more.
 */
class SalesAnalytics
{
    private const WEEK_DAYS = 7;

    private const TOP_PRODUCTS = 5;

    private const QUARTER_MINUTES = 15;

    public function __construct(
        private DailySalesReport $report,
        private StoreAvailability $store,
    ) {}

    /** @return array<string, mixed> */
    public function now(): array
    {
        $timezone = $this->store->timezone();
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $tomorrow = $today->addDay();
        $weekStart = $today->subDays(self::WEEK_DAYS - 1);

        $days = [];

        for ($day = $weekStart; $day->lessThan($tomorrow); $day = $day->addDay()) {
            $days[$day->toDateString()] = $this->bucket($day->toDateString()) + ['hours' => $this->emptyHours()];
        }

        foreach ($this->report->fulfilledRows($weekStart, $tomorrow) as $row) {
            $closed = CarbonImmutable::parse($row->closed, 'UTC')->setTimezone($timezone);
            $date = $closed->toDateString();

            if (! isset($days[$date])) {
                continue;
            }

            $amount = (float) $row->amount;
            $hour = (int) $closed->format('G');
            $quarter = (int) floor((int) $closed->format('i') / self::QUARTER_MINUTES);

            $this->add($days[$date], $amount);
            $this->add($days[$date]['hours'][$hour], $amount);
            $this->add($days[$date]['hours'][$hour]['quarters'][$quarter], $amount);
        }

        return [
            'timezone' => $timezone,
            'generated_at' => now()->toIso8601String(),
            'sales' => [
                'days' => array_map(fn (array $day) => ['hours' => $this->tradingHours($day['hours'])] + $day, array_values($days)),
            ],
            'products' => $this->report->topProducts($weekStart, $tomorrow, self::TOP_PRODUCTS),
        ];
    }

    private function bucket(int|string $key): array
    {
        return ['key' => $key, 'orders' => 0, 'value' => 0.0];
    }

    private function emptyHours(): array
    {
        $hours = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $quarters = array_map(fn (int $minute) => $this->bucket($minute), range(0, 45, self::QUARTER_MINUTES));

            $hours[$hour] = $this->bucket($hour) + ['quarters' => $quarters];
        }

        return $hours;
    }

    private function add(array &$bucket, float $amount): void
    {
        $bucket['orders']++;
        $bucket['value'] = round($bucket['value'] + $amount, 2);
    }

    /** The hours the store trades, widened to take in any sale outside them. */
    private function tradingHours(array $hourly): array
    {
        $sold = array_keys(array_filter($hourly, fn (array $bucket) => $bucket['orders'] > 0));
        $opens = (int) substr($this->store->opensAt(), 0, 2);
        $closes = (int) substr($this->store->closesAt(), 0, 2);
        $first = min($opens, ...($sold ?: [$opens]));
        $last = max($closes, ...($sold ?: [$closes]));

        return array_values(array_filter($hourly, fn (array $bucket) => $bucket['key'] >= $first && $bucket['key'] <= $last));
    }
}
