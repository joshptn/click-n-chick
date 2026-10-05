<?php

namespace App\Console\Commands;

use App\Models\Cart;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Deletes guest carts nobody came back for.
 *
 * A guest cart has no account to hang off, so nothing else ever removes one: not
 * a sign-in (that merges and deletes only the carts it finds), not an order (that
 * clears the lines it bought and leaves the rest). Without this they accumulate
 * one per visitor who added something and left.
 */
class PruneGuestCarts extends Command
{
    protected $signature = 'guest:prune-carts {--dry-run : Count what would be deleted without deleting it}';

    protected $description = 'Delete guest carts that have sat idle past the configured window';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now()->subDays((int) config('store.guest.cart_idle_days'));

        // Idle means the cart AND its lines. Changing a line does not touch the
        // cart's own updated_at, so a guest still adjusting quantities would
        // otherwise look abandoned from the day the cart was made.
        $idle = Cart::query()
            ->whereNull('user_id')
            ->where('updated_at', '<', $cutoff)
            ->whereDoesntHave('items', fn ($lines) => $lines->where('updated_at', '>=', $cutoff))
            ->pluck('id');

        if ($idle->isEmpty()) {
            $this->info('No idle guest carts.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Would delete {$idle->count()} idle guest cart(s).");

            return self::SUCCESS;
        }

        // Lines and their add-on rows go with the cart, by cascade.
        Cart::query()->whereIn('id', $idle)->delete();

        $this->info("Deleted {$idle->count()} idle guest cart(s).");

        return self::SUCCESS;
    }
}
