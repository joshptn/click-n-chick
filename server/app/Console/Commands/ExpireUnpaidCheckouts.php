<?php

namespace App\Console\Commands;

use App\Services\Payments\CheckoutExpiry;
use Illuminate\Console\Command;

/** Cancel immediate orders not paid within 10 minutes of checkout (FR-04.5). */
class ExpireUnpaidCheckouts extends Command
{
    protected $signature = 'payments:expire-checkouts';

    protected $description = 'Cancel immediate orders that were not paid within 10 minutes';

    public function handle(CheckoutExpiry $expiry): int
    {
        $cancelled = $expiry->due()->filter(fn ($order) => $expiry->expire($order) !== null)->count();

        $this->info($cancelled === 0 ? 'No unpaid checkouts to cancel.' : "{$cancelled} unpaid checkout(s) cancelled.");

        return self::SUCCESS;
    }
}
