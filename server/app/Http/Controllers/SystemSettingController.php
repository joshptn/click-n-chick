<?php

namespace App\Http\Controllers;

use App\Events\NotificationBroadcast;
use App\Models\Discount;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Store-Manager-governed system configuration (UC-ADMIN-003, UC-ADMIN-007).
 *
 * Route-gated to super_admin, which is the whole of "Store Manager only"
 * (BR-27, BR-14, FR-05.5) - there is no per-resource question to ask here, so
 * there is no policy, only the route group.
 *
 * Today this governs the statutory discount rate. Delivery rates, operating
 * hours, and the sales-performance thresholds land here too when their modules
 * arrive; the settings table is already shaped for them.
 */
class SystemSettingController extends Controller
{
    /** GET /api/admin/settings/discount */
    public function showDiscount()
    {
        return response()->json($this->discountPayload());
    }

    /**
     * PUT /api/admin/settings/discount
     *
     * The 20% floor (BR-34) is enforced by the validation rule, so a value
     * below the statutory rate is refused with a field error the Manager can
     * read - rather than being silently clamped, which would tell them the save
     * worked while storing something else.
     *
     * Discount::currentPercentage() clamps on read as well. That is not
     * redundancy for its own sake: this endpoint is not the only way a row can
     * reach the table, and a seed or a direct database edit bypasses every rule
     * written here.
     */
    public function updateDiscount(Request $request)
    {
        $validated = $request->validate([
            'percentage' => [
                'required',
                'numeric',
                'min:'.Discount::MINIMUM_PERCENTAGE,
                'max:100',
            ],
        ], [
            'percentage.min' => 'The discount rate cannot go below the statutory '
                .Discount::MINIMUM_PERCENTAGE.'%.',
        ]);

        $previous = Discount::currentPercentage();
        $next = round((float) $validated['percentage'], 2);

        if ($previous === $next) {
            return response()->json([
                'success' => true,
                'message' => 'The discount rate is already '.$this->format($next).'%.',
            ] + $this->discountPayload());
        }

        Setting::put(Setting::DISCOUNT_PERCENTAGE, $next, (int) $request->user()->getKey());

        $this->announceRateChange($previous, $next);

        return response()->json([
            'success' => true,
            'message' => 'The statutory discount rate is now '.$this->format($next).'%.',
        ] + $this->discountPayload());
    }

    /**
     * BR-27: every user is told when the rate changes.
     *
     * Chunked rather than loaded at once, because this is one row per account
     * and the query would otherwise grow with the user table. It runs inline:
     * at this store's scale that is a short loop, and a queued job would need a
     * worker running to be reliable. Worth moving behind the queue if the user
     * count ever makes this a slow request.
     *
     * Wrapped whole: the setting is already saved by the time this runs, so a
     * failure to announce must not turn a completed change into a 500 that
     * tells the Manager it did not happen.
     */
    private function announceRateChange(float $previous, float $next): void
    {
        $title = 'Discount rate updated';
        $body = 'The Senior Citizen and PWD discount is now '.$this->format($next)
            .'%, changed from '.$this->format($previous).'%.';

        try {
            User::query()
                ->select('id')
                ->chunkById(500, function ($users) use ($title, $body) {
                    foreach ($users as $user) {
                        $notification = Notification::create([
                            'user_id' => $user->id,
                            'title' => $title,
                            'body' => $body,
                            'is_read' => false,
                        ]);

                        try {
                            NotificationBroadcast::dispatch($notification, (int) $user->id);
                        } catch (Throwable $e) {
                            // One dead socket must not stop the rest of the
                            // announcement; the row is already written and the
                            // bell will show it on next load.
                            Log::warning('Could not broadcast a discount rate change.', [
                                'user_id' => $user->id,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                });
        } catch (Throwable $e) {
            Log::warning('Could not announce the discount rate change.', ['error' => $e->getMessage()]);
        }
    }

    /** Trims a trailing '.00' so the copy reads "20%", not "20.00%". */
    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** @return array<string, mixed> */
    private function discountPayload(): array
    {
        $setting = Setting::query()
            ->with('updatedBy:id,first_name,last_name')
            ->where('key', Setting::DISCOUNT_PERCENTAGE)
            ->first();

        return [
            'percentage' => Discount::currentPercentage(),
            'minimum_percentage' => Discount::MINIMUM_PERCENTAGE,
            'updated_at' => $setting?->updated_at?->toIso8601String(),
            'updated_by' => $setting?->updatedBy,
        ];
    }
}
