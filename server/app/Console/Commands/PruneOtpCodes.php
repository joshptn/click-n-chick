<?php

namespace App\Console\Commands;

use App\Models\OtpCode;
use Illuminate\Console\Command;

/**
 * Deletes one-time codes that can no longer be redeemed: used ones, and ones
 * past their expiry. Scheduled daily (routes/console.php).
 *
 * Only rows older than a day go, because the resend cooldown is measured from
 * the newest code for an identifier, used or not, and deleting a code the
 * moment it is spent would reopen the cooldown.
 */
class PruneOtpCodes extends Command
{
    protected $signature = 'otp:prune
                            {--hours=24 : Leave codes newer than this alone}
                            {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete used and expired one-time codes';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) $this->option('hours'));

        $query = OtpCode::query()
            ->where('created_at', '<', $cutoff)
            ->where(fn ($spent) => $spent
                ->whereNotNull('consumed_at')
                ->orWhere('expires_at', '<', now()));

        $count = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} code(s) would be deleted.");

            return self::SUCCESS;
        }

        $query->delete();

        $this->info("{$count} code(s) deleted.");

        return self::SUCCESS;
    }
}
