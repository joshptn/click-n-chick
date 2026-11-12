<?php

namespace App\Console\Commands;

use App\Services\Orders\AdvanceExpiry;
use Illuminate\Console\Command;

class ExpireUnpaidAdvanceOrders extends Command
{
    protected $signature = 'advance:expire-unpaid {--dry-run : List what would be cancelled without cancelling it}';

    protected $description = 'Cancel unpaid advance requests past their payment deadline or collection time';

    public function handle(AdvanceExpiry $expiry): int
    {
        $due = $expiry->due();

        if ($due->isEmpty()) {
            $this->info('Nothing to expire.');

            return self::SUCCESS;
        }

        $cancelled = 0;

        foreach ($due as $order) {
            $reason = $expiry->reasonFor($order);

            if ($reason === null) {
                continue;
            }

            $this->line("#{$order->id} ({$order->status}): {$reason}");

            if ($this->option('dry-run')) {
                $cancelled++;

                continue;
            }

            if ($expiry->expireAndAnnounce($order) !== null) {
                $cancelled++;
            }
        }

        $this->info(
            $this->option('dry-run')
                ? "{$cancelled} request(s) would be cancelled."
                : "{$cancelled} request(s) cancelled."
        );

        return self::SUCCESS;
    }
}
