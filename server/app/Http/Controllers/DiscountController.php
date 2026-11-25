<?php

namespace App\Http\Controllers;

use App\Events\DiscountClaimBroadcast;
use App\Events\NotificationBroadcast;
use App\Jobs\SendPushNotification;
use App\Models\Discount;
use App\Models\Notification;
use App\Models\User;
use App\Utils\Image;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Statutory discount eligibility: applying, and the Store Agent's decision.
 *
 * Scope is deliberately narrow - this decides WHETHER someone is entitled.
 * What comes off a given basket is computed at checkout against the approved
 * entitlement and lands in orders.discount_amount; none of that lives here.
 *
 * The customer half is scoped to $request->user(). The staff half is gated by
 * DiscountPolicy, which is where "who may approve" is decided.
 */
class DiscountController extends Controller
{
    use AuthorizesRequests;

    /** notifications.notification_type of a "new application to review" notice. */
    public const NOTICE_REVIEW = 'discount_review';

    /**
     * notifications.notification_type of the customer's "approved / not
     * approved" notice, so an open page showing their standing (the Advance
     * Order banner, Profile) refreshes when it arrives.
     */
    public const NOTICE_DECISION = 'discount_decision';

    /** GET /api/discount - the caller's own standing. */
    public function show(Request $request)
    {
        $claim = $request->user()->latestDiscountClaim()->first();

        return response()->json($this->standing($claim));
    }

    /**
     * POST /api/discount - apply, with a photo of the ID.
     *
     * One live claim at a time. Pending blocks (an agent is already looking at
     * it) and approved blocks (they already have it); a rejection does not, so
     * an unreadable photo is recoverable rather than a permanent bar.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Discount::class);

        $validated = $request->validate([
            'discount_type' => ['required', 'string', Rule::in(Discount::types())],
            'id_image' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:5120'],
        ]);

        $user = $request->user();

        if (($existing = Discount::activeFor((int) $user->getKey())) !== null) {
            return response()->json([
                'success' => false,
                'code' => $existing->isApproved() ? 'ALREADY_ELIGIBLE' : 'ALREADY_PENDING',
                'message' => $existing->isApproved()
                    ? 'Your discount has already been approved.'
                    : 'Your application is already with a Store Agent for review.',
            ] + $this->standing($existing), 422);
        }

        $url = Image::uploadImage($request->file('id_image'), 'discount-ids');

        // Upload before the row, and bail if it failed: a claim with no image
        // is one an agent can only reject, and it would still consume the
        // customer's "one live claim".
        if ($url === null) {
            return response()->json([
                'success' => false,
                'message' => 'We could not upload that image. Please try again.',
            ], 502);
        }

        $claim = Discount::create([
            'user_id' => $user->getKey(),
            'discount_type' => $validated['discount_type'],
            'id_image' => $url,
            'discount_status' => Discount::STATUS_PENDING,
        ]);

        $this->notifyReviewers($claim, $user);

        DiscountClaimBroadcast::dispatch($claim, DiscountClaimBroadcast::SUBMITTED);

        return response()->json([
            'success' => true,
            'message' => 'Your ID has been sent to a Store Agent for review.',
        ] + $this->standing($claim), 201);
    }

    // -----------------------------------------------------------------
    // Store Agent review
    // -----------------------------------------------------------------

    /** GET /api/admin/discount-claims?status=pending */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Discount::class);

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in([
                Discount::STATUS_PENDING,
                Discount::STATUS_APPROVED,
                Discount::STATUS_REJECTED,
            ])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $status = $validated['status'] ?? Discount::STATUS_PENDING;

        $claims = Discount::query()
            ->with([
                'user:id,first_name,last_name,email,phone_number',
                'verifier:id,first_name,last_name',
            ])
            ->where('discount_status', $status)
            // Whether an undo can reopen it: only the customer's latest claim.
            ->select('discounts.*')
            ->selectRaw('NOT EXISTS (SELECT 1 FROM discounts AS newer WHERE newer.user_id = discounts.user_id AND newer.id > discounts.id) AS is_latest')
            // Pending, oldest first: a review queue that serves the newest
            // first starves whoever has waited longest. Decided, latest first:
            // that list is a record, read for what just happened.
            ->when(
                $status === Discount::STATUS_PENDING,
                fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                fn ($query) => $query->orderByDesc('verified_at')->orderByDesc('id'),
            )
            ->paginate($validated['per_page'] ?? 20);

        $counts = Discount::query()
            ->selectRaw('discount_status, count(*) as total')
            ->groupBy('discount_status')
            ->pluck('total', 'discount_status');

        return response()->json($claims->toArray() + [
            'counts' => [
                Discount::STATUS_PENDING => (int) ($counts[Discount::STATUS_PENDING] ?? 0),
                Discount::STATUS_APPROVED => (int) ($counts[Discount::STATUS_APPROVED] ?? 0),
                Discount::STATUS_REJECTED => (int) ($counts[Discount::STATUS_REJECTED] ?? 0),
            ],
            // What an approval grants, read live like the customer's own page.
            'percentage' => Discount::currentPercentage(),
        ]);
    }

    /** POST /api/admin/discount-claims/{discount}/approve */
    public function approve(Request $request, Discount $discount)
    {
        $this->authorize('approve', $discount);

        return $this->decide($request, $discount, Discount::STATUS_APPROVED, null);
    }

    /** POST /api/admin/discount-claims/{discount}/reject */
    public function reject(Request $request, Discount $discount)
    {
        $this->authorize('reject', $discount);

        $validated = $request->validate([
            // Required, not optional: a rejection the customer cannot act on
            // just produces a second identical application.
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        return $this->decide($request, $discount, Discount::STATUS_REJECTED, $validated['rejection_reason']);
    }

    /**
     * POST /api/admin/discount-claims/{discount}/undo - password every time.
     *
     * A safety net for a decision made without a proper look at the ID: the
     * claim goes back to Pending for a fresh review, whenever it was decided.
     * Only the customer's latest claim can be reopened, since an older one
     * has been replaced by the application they sent after it. Orders already
     * placed with the discount keep it (decided 2026-11-23).
     */
    public function undo(Request $request, Discount $discount)
    {
        $this->authorize('undo', $discount);

        $reopened = DB::transaction(function () use ($discount) {
            $fresh = Discount::query()->whereKey($discount->getKey())->lockForUpdate()->first();

            if ($fresh === null || $fresh->isPending()) {
                return 'NOT_DECIDED';
            }

            $replaced = Discount::query()
                ->where('user_id', $fresh->user_id)
                ->where('id', '>', $fresh->getKey())
                ->exists();

            if ($replaced) {
                return 'REPLACED';
            }

            $was = $fresh->discount_status;

            $fresh->forceFill([
                'discount_status' => Discount::STATUS_PENDING,
                'rejection_reason' => null,
                'verified_by' => null,
                'verified_at' => null,
            ])->save();

            return [$fresh, $was];
        });

        if ($reopened === 'NOT_DECIDED') {
            return response()->json([
                'success' => false,
                'code' => 'NOT_DECIDED',
                'message' => 'That application is already waiting for review.',
            ], 409);
        }

        if ($reopened === 'REPLACED') {
            return response()->json([
                'success' => false,
                'code' => 'REPLACED',
                'message' => 'The customer has sent a newer application since, so this one can no longer be reopened.',
            ], 409);
        }

        [$claim, $was] = $reopened;

        $this->push(
            (int) $claim->user_id,
            'Discount under review again',
            $was === Discount::STATUS_APPROVED
                ? "Your {$claim->typeLabel()} discount is being reviewed again, so it cannot be used until it is approved. We will let you know once it is decided."
                : "Your {$claim->typeLabel()} application is being reviewed again. We will let you know once it is decided.",
            null,
            true
        );

        DiscountClaimBroadcast::dispatch($claim, DiscountClaimBroadcast::REOPENED);

        $claim = $claim->fresh(['user:id,first_name,last_name,email,phone_number']);
        $claim->setAttribute('is_latest', true);

        return response()->json([
            'success' => true,
            'message' => 'Decision undone. The application is back in Pending.',
            'claim' => $claim,
        ]);
    }

    /**
     * Settle a claim.
     *
     * Guarded against a double decision: two agents opening the same queue
     * would otherwise both write, and the second would silently overwrite the
     * first - including flipping an approval back to a rejection.
     */
    private function decide(Request $request, Discount $discount, string $status, ?string $reason)
    {
        $settled = DB::transaction(function () use ($request, $discount, $status, $reason) {
            $fresh = Discount::query()->whereKey($discount->getKey())->lockForUpdate()->first();

            if ($fresh === null || ! $fresh->isPending()) {
                return null;
            }

            $fresh->forceFill([
                'discount_status' => $status,
                'rejection_reason' => $reason,
                'verified_by' => $request->user()->getKey(),
                'verified_at' => now(),
            ])->save();

            return $fresh;
        });

        if ($settled === null) {
            return response()->json([
                'success' => false,
                'code' => 'ALREADY_REVIEWED',
                'message' => 'That application has already been reviewed.',
            ], 409);
        }

        $this->notifyClaimant($settled);

        DiscountClaimBroadcast::dispatch($settled, DiscountClaimBroadcast::DECIDED);

        $claim = $settled->fresh(['user:id,first_name,last_name,email', 'verifier:id,first_name,last_name']);
        // A pending claim is always the customer's latest (one live claim at a
        // time), so the one just decided can be undone.
        $claim->setAttribute('is_latest', true);

        return response()->json([
            'success' => true,
            'message' => $settled->isApproved()
                ? 'Discount approved.'
                : 'Discount application rejected.',
            'claim' => $claim,
        ]);
    }

    // -----------------------------------------------------------------
    // Notifications
    // -----------------------------------------------------------------

    /**
     * Tell the Store Agents there is something to review.
     *
     * Typed, so the staff bell can open the Pending Verification queue.
     */
    private function notifyReviewers(Discount $claim, User $claimant): void
    {
        // The Store Manager cannot decide one, so is not asked to. The queue and
        // its count still update for them over admin.discounts.
        $reviewers = User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('account_status', User::STATUS_ACTIVE)
            ->get(['id']);

        $name = trim("{$claimant->first_name} {$claimant->last_name}") ?: 'A customer';

        foreach ($reviewers as $reviewer) {
            $this->push(
                (int) $reviewer->id,
                'Discount application to review',
                "{$name} submitted a {$claim->typeLabel()} ID for approval.",
                self::NOTICE_REVIEW
            );
        }
    }

    private function notifyClaimant(Discount $claim): void
    {
        $approved = $claim->isApproved();

        $this->push(
            (int) $claim->user_id,
            $approved ? 'Discount approved' : 'Discount application rejected',
            $approved
                ? "Your {$claim->typeLabel()} discount has been approved. You can use it on your orders."
                : "Your {$claim->typeLabel()} application was not approved. {$claim->rejection_reason}",
            self::NOTICE_DECISION,
            true
        );
    }

    /**
     * Write and broadcast one notification, and push it to the phone when asked.
     *
     * Guarded end to end: the decision is already committed by the time this
     * runs, so a broadcast transport that is down must not turn a completed
     * approval into a 500 telling the agent it failed.
     */
    private function push(int $userId, string $title, string $body, ?string $type = null, bool $toPhone = false): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'notification_type' => $type,
                'is_read' => false,
            ]);

            NotificationBroadcast::dispatch($notification, $userId);

            // The customer's own decisions also reach their phone.
            if ($toPhone) {
                SendPushNotification::forNotification($notification);
            }
        } catch (Throwable $e) {
            Log::warning('Could not send a discount notification.', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function standing(?Discount $claim): array
    {
        return [
            'claim' => $claim,
            'is_eligible' => $claim?->isApproved() ?? false,
            'can_apply' => $claim === null || $claim->isRejected(),
            'types' => Discount::types(),
            // Read live, so a rate change is reflected the next time any
            // customer opens the page - not frozen at approval time.
            'percentage' => Discount::currentPercentage(),
        ];
    }
}
