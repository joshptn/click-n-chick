<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Poster;
use App\Models\User;
use App\Services\Store\StoreAvailability;
use App\Utils\Notification as Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The day before a poster expires, Store Agents and Store Managers are told,
 * live and in their notifications (decided 2026-11-26). Every poster counts,
 * On Display or not; customers are never told. Adding, editing and deleting a
 * poster notify no one.
 *
 * Runs hourly from opening time, so a poster given tomorrow's date later in
 * the day is still announced; each poster is announced once that day.
 */
class WarnExpiringPosters extends Command
{
    public const TYPE = 'poster_expiry';

    protected $signature = 'posters:warn-expiring {--dry-run : List what would be sent without sending it}';

    protected $description = 'Tell staff which posters expire tomorrow';

    public function handle(StoreAvailability $store): int
    {
        $now = CarbonImmutable::now($store->timezone());

        if ($now->format('H:i') < $store->opensAt()) {
            $this->info("Before opening ({$store->opensAt()}); nothing sent yet.");

            return self::SUCCESS;
        }

        $tomorrow = $now->addDay();

        $posters = Poster::query()
            ->whereDate('expires_at', $tomorrow->toDateString())
            ->orderBy('id')
            ->get()
            ->reject(fn (Poster $poster) => $this->alreadyWarned($poster, $now));

        if ($posters->isEmpty()) {
            $this->info('No posters expire tomorrow that staff have not already been told about.');

            return self::SUCCESS;
        }

        $staff = User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->where('account_status', User::STATUS_ACTIVE)
            ->pluck('id');

        foreach ($posters as $poster) {
            $this->line("Poster #{$poster->id} expires {$tomorrow->toDateString()}");

            if ($this->option('dry-run')) {
                continue;
            }

            foreach ($staff as $id) {
                try {
                    Notifier::push((int) $id, self::title($poster), $this->body($poster, $tomorrow), self::TYPE);
                } catch (Throwable $e) {
                    Log::warning('A poster expiry warning could not be sent.', [
                        'poster_id' => $poster->id,
                        'user_id' => $id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info(
            $this->option('dry-run')
                ? $posters->count().' poster(s) would be announced.'
                : $posters->count().' poster(s) announced to '.$staff->count().' staff account(s).'
        );

        return self::SUCCESS;
    }

    public static function title(Poster $poster): string
    {
        return "Poster #{$poster->id} expires tomorrow";
    }

    private function body(Poster $poster, CarbonImmutable $tomorrow): string
    {
        $date = $tomorrow->format('M j, Y');

        return $poster->is_active
            ? "It shows on the homepage until the end of {$date}. A later date can be set in Store Settings."
            : "It expires on {$date}. It is switched off, so customers do not see it now.";
    }

    private function alreadyWarned(Poster $poster, CarbonImmutable $now): bool
    {
        return Notification::query()
            ->where('notification_type', self::TYPE)
            ->where('title', self::title($poster))
            ->where('created_at', '>=', $now->startOfDay()->utc())
            ->exists();
    }
}
