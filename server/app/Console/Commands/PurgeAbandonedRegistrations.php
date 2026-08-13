<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Deletes signups that never completed phone verification.
 *
 * NOT SCHEDULED. Nothing invokes this automatically - there is no scheduler
 * running yet because deployment is deferred. Run it by hand, or register it
 * in routes/console.php once a scheduler exists.
 *
 * Deleting rather than flagging: a pending row holds a unique email and a
 * unique phone_number_hash but has no verified owner and no history worth
 * keeping. Leaving it parked would squat on details a real customer may need,
 * and registration already treats a row past the window as claimable, so
 * keeping it adds nothing. otp_codes rows cascade with the user.
 */
class PurgeAbandonedRegistrations extends Command
{
    protected $signature = 'registrations:purge-abandoned
                            {--hours= : Override the abandonment window}
                            {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete pending_verification accounts past the abandonment window';

    public function handle(): int
    {
        $hours = (int) ($this->option('hours') ?: User::PENDING_VERIFICATION_HOURS);
        $cutoff = now()->subHours($hours);

        $query = User::query()
            ->where('account_status', User::STATUS_PENDING_VERIFICATION)
            ->where('created_at', '<=', $cutoff);

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info("No abandoned registrations older than {$hours}h.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("[dry run] {$count} abandoned registration(s) older than {$hours}h would be deleted.");

            $this->table(
                ['id', 'email', 'created_at'],
                (clone $query)->limit(25)->get(['id', 'email', 'created_at'])
                    ->map(fn ($u) => [$u->id, $u->email, (string) $u->created_at])
                    ->all()
            );

            return self::SUCCESS;
        }

        // Deleted individually so the otp_codes cascade and any model events fire.
        $deleted = 0;
        $query->each(function (User $user) use (&$deleted) {
            $user->delete();
            $deleted++;
        });

        $this->info("Deleted {$deleted} abandoned registration(s) older than {$hours}h.");

        return self::SUCCESS;
    }
}
