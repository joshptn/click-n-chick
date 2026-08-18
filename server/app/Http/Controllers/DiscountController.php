<?php

namespace App\Http\Controllers;

use App\Events\NotificationBroadcast;
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
            'discount_percentage' => Discount::STATUTORY_PERCENTAGE,
            'vat_exempt' => true,
            'id_image' => $url,
            'discount_status' => Discount::STATUS_PENDING,
        ]);

        $this->notifyReviewers($claim, $user);

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

        $claims = Discount::query()
            ->with([
                'user:id,first_name,last_name,email,phone_number',
                'verifier:id,first_name,last_name',
            ])
            ->where('discount_status', $validated['status'] ?? Discount::STATUS_PENDING)
            // Oldest first: a review queue that serves the newest first
            // starves whoever has waited longest.
            ->orderBy('created_at')
            ->paginate($validated['per_page'] ?? 20);

        return response()->json($claims);
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

        return response()->json([
            'success' => true,
            'message' => $settled->isApproved()
                ? 'Discount approved.'
                : 'Discount application rejected.',
            'claim' => $settled->fresh(['user:id,first_name,last_name,email', 'verifier:id,first_name,last_name']),
        ]);
    }

    // -----------------------------------------------------------------
    // Notifications
    // -----------------------------------------------------------------

    /**
     * Tell the Store Agents there is something to review.
     *
     * This is the "directed to the store agent" step. There is no agent screen
     * yet, so the notification plus GET /api/admin/discount-claims is the whole
     * hand-off - the work is queued and visible even without a UI to browse it.
     */
    private function notifyReviewers(Discount $claim, User $claimant): void
    {
        $reviewers = User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN])
            ->get(['id']);

        $name = trim("{$claimant->first_name} {$claimant->last_name}") ?: 'A customer';

        foreach ($reviewers as $reviewer) {
            $this->push(
                (int) $reviewer->id,
                'Discount application to review',
                "{$name} submitted a {$claim->typeLabel()} ID for approval."
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
                : "Your {$claim->typeLabel()} application was not approved. {$claim->rejection_reason}"
        );
    }

    /**
     * Write and broadcast one notification.
     *
     * Guarded end to end: the decision is already committed by the time this
     * runs, so a broadcast transport that is down must not turn a completed
     * approval into a 500 telling the agent it failed.
     */
    private function push(int $userId, string $title, string $body): void
    {
        try {
            $notification = Notification::create([
                'user_id' => $userId,
                'title' => $title,
                'body' => $body,
                'is_read' => false,
            ]);

            NotificationBroadcast::dispatch($notification, $userId);
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
            'percentage' => Discount::STATUTORY_PERCENTAGE,
        ];
    }
}
