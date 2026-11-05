<?php

namespace App\Console\Commands;

use App\Services\Stock\StockReservation;
use Illuminate\Console\Command;

/**
 * Lapse stock holds whose payment window closed unpaid (FR-04.5).
 *
 * Housekeeping, not the gate: a lapsed hold already stops counting the moment
 * it expires (StockHold::scopeActive). This marks it expired and tells open
 * menus the units are back.
 */
class ExpireStockHolds extends Command
{
    protected $signature = 'stock:expire-holds';

    protected $description = 'Release stock held for payments that never completed';

    public function handle(StockReservation $stock): int
    {
        $count = $stock->expireStale();

        $this->info($count === 0 ? 'No stock holds to expire.' : "{$count} stock hold(s) expired.");

        return self::SUCCESS;
    }
}
