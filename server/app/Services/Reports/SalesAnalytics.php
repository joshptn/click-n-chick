<?php

namespace App\Services\Reports;

use App\Services\Store\StoreAvailability;
use Carbon\CarbonImmutable;

/**
 * The sales chart and top products on the Analytics Dashboard (UC-REP-001).
 *
 * The same sales as the Daily Sales Report: orders that closed as completed or
 * delivered, on the store day they closed, at their checkout totals (BR-42).
 */
class SalesAnalytics
{
    private const WEEK_DAYS = 7;

    private const MONTH_DAYS = 30;

    private const TOP_PRODUCTS = 5;

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
        $todayKey = $today->toDateString();

        $daily = [];

        for ($day = $today->subDays(self::MONTH_DAYS - 1); $day->lessThan($tomorrow); $day = $day->addDay()) {
            $daily[$day->toDateString()] = ['key' => $day->toDateString(), 'orders' => 0, 'value' => 0.0];
        }

        $hourly = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $hourly[$hour] = ['key' => $hour, 'orders' => 0, 'value' => 0.0];
        }

        foreach ($this->report->fulfilledRows($today->subDays(self::MONTH_DAYS - 1), $tomorrow) as $row) {
            $closed = CarbonImmutable::parse($row->closed, 'UTC')->setTimezone($timezone);
            $date = $closed->toDateString();

            if (! isset($daily[$date])) {
                continue;
            }

            $this->add($daily[$date], (float) $row->amount);

            if ($date === $todayKey) {
                $this->add($hourly[(int) $closed->format('G')], (float) $row->amount);
            }
        }

        $weekStart = $today->subDays(self::WEEK_DAYS - 1);

        return [
            'timezone' => $timezone,
            'generated_at' => now()->toIso8601String(),
            'sales' => [
                'weekly' => array_slice(array_values($daily), -self::WEEK_DAYS),
                'daily' => array_values($daily),
                'hourly' => $this->tradingHours($hourly),
            ],
            'products' => $this->report->topProducts($weekStart, $tomorrow, self::TOP_PRODUCTS),
        ];
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
