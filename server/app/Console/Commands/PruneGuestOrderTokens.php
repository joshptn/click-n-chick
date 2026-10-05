<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

/**
 * Clears tracking credentials whose window has closed.
 *
 * Not needed for correctness - the window is decided by time, so a lapsed hash
 * already grants nothing. It runs so a database copy carries fewer values that
 * look live, and so the column reflects what is actually reachable.
 */
class PruneGuestOrderTokens extends Command
{
    protected $signature = 'guest:prune-tokens {--dry-run : Count what would be cleared without clearing it}';

    protected $description = 'Clear guest tracking tokens whose access window has closed';

    public function handle(): int
    {
        // Filtered in PHP rather than in SQL because the window is two rules -
        // open orders run from created_at, closed ones from closed_at - and
        // Order::guestAccessIsLive is the one place that decides it.
        $lapsed = Order::query()
            ->whereNotNull('guest_token_hash')
            ->get()
            ->reject(fn (Order $order) => $order->guestAccessIsLive());

        if ($lapsed->isEmpty()) {
            $this->info('No guest tracking tokens have lapsed.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Would clear {$lapsed->count()} lapsed guest tracking token(s).");

            return self::SUCCESS;
        }

        Order::query()
            ->whereIn('id', $lapsed->modelKeys())
            ->update(['guest_token_hash' => null]);

        $this->info("Cleared {$lapsed->count()} lapsed guest tracking token(s).");

        return self::SUCCESS;
    }
}
