<?php

namespace App\Services\Catalog;

use App\Events\PriceBroadcast;
use App\Jobs\NotifyEveryAccount;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telling everyone that menu prices changed (BR-18, UC-NOTIF-009), the same
 * way for one dish and for a bulk change.
 *
 *   live    PriceBroadcast on `menu`: open menus, carts and checkouts refetch,
 *           so their totals float at once (FR-02.5)
 *   notice  one in-app notice to every account, pushed to customers' devices
 *           (FR-07.8), written by the queue worker
 *
 * The prices are saved before this runs, so a failure is logged, not thrown.
 */
final class PriceAnnouncer
{
    public const TITLE = 'Price update';

    /** @param  list<int>  $foodIds */
    public function announce(string $message, array $foodIds): void
    {
        try {
            PriceBroadcast::dispatch($message, array_values(array_map('intval', $foodIds)));
        } catch (Throwable $e) {
            Log::warning('Could not broadcast a price change.', ['foods' => count($foodIds), 'error' => $e->getMessage()]);
        }

        try {
            NotifyEveryAccount::dispatch(self::TITLE, $message);
        } catch (Throwable $e) {
            Log::warning('Could not announce a price change.', ['foods' => count($foodIds), 'error' => $e->getMessage()]);
        }
    }

    public static function peso(int $amount): string
    {
        return '₱'.number_format($amount);
    }
}
