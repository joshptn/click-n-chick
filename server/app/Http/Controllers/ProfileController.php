<?php

namespace App\Http\Controllers;

use App\Models\Discount;
use App\Models\User;
use App\Services\Verification\Channel;
use App\Utils\Image;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The customer's own account page (UC-PROF-001 / UC-PROF-002).
 *
 * Everything here is scoped to $request->user(). There is no id parameter on
 * any route in this controller, so "edit someone else's profile" is not a
 * request that can be expressed rather than one that is checked for.
 */
class ProfileController extends Controller
{
    /** GET /api/profile */
    public function show(Request $request)
    {
        $user = $request->user();

        return response()->json($this->payload($user));
    }

    /**
     * PUT /api/profile
     *
     * Names and mobile number only. Email is deliberately not editable here -
     * see the note in payload().
     */
    public function update(Request $request)
    {
        $validated = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'required', 'string', 'max:20', 'regex:/^[0-9+\-\s()]+$/'],
            // RA 10173 consent. Sent as a boolean; stored as a timestamp,
            // because demonstrating consent means knowing when it was given.
            'privacy_consent' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();

        if (array_key_exists('first_name', $validated)) {
            $user->first_name = $validated['first_name'];
        }

        if (array_key_exists('last_name', $validated)) {
            $user->last_name = $validated['last_name'];
        }

        if (array_key_exists('phone_number', $validated)) {
            $this->applyPhoneNumber($user, $validated['phone_number']);
        }

        if (array_key_exists('privacy_consent', $validated)) {
            // Withdrawal is just clearing it, which is what makes the timestamp
            // the right shape for this rather than a boolean.
            $user->privacy_consent_at = $validated['privacy_consent'] ? ($user->privacy_consent_at ?? now()) : null;
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Your profile has been saved.',
        ] + $this->payload($user->fresh()));
    }

    /**
     * Change the mobile number, refusing the cases that would lock the account.
     *
     * The number is a credential, not a display field: it is a login
     * identifier, it can be the channel that gates sign-in, and it can be where
     * 2FA codes go. Letting it change freely while phone_verified_at stayed set
     * would leave the account "verified" for a number nobody ever proved they
     * hold - and, with SMS 2FA on, would send every future code to it.
     *
     * So the change is refused outright while the number is load-bearing, and
     * otherwise clears the verification it invalidates.
     */
    private function applyPhoneNumber(User $user, string $submitted): void
    {
        $hash = User::hashPhoneNumber($submitted);

        if ($hash === null) {
            throw ValidationException::withMessages([
                'phone_number' => 'Enter a valid Philippine mobile number.',
            ]);
        }

        // Unchanged - nothing to validate or invalidate.
        if ($hash === $user->phone_number_hash) {
            return;
        }

        if (User::where('phone_number_hash', $hash)->whereKeyNot($user->getKey())->exists()) {
            throw ValidationException::withMessages([
                'phone_number' => 'This phone number is already registered.',
            ]);
        }

        if ($user->verification_channel === Channel::Sms->value) {
            throw ValidationException::withMessages([
                'phone_number' => 'Your phone is how you verify sign-ins, so it cannot be changed here yet. Verify your email address first, then switch.',
            ]);
        }

        if ($user->two_factor_channel === Channel::Sms->value) {
            throw ValidationException::withMessages([
                'phone_number' => 'Two-step verification sends codes to this number. Turn it off, or move it to email, before changing your phone.',
            ]);
        }

        $user->phone_number = $submitted;
        $user->phone_number_hash = $hash;
        // The new number has not been proven, so it is not verified.
        $user->phone_verified_at = null;
    }

    /** POST /api/profile/avatar */
    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $user = $request->user();
        $previous = $user->avatar;

        $url = Image::uploadImage($request->file('avatar'), 'avatars');

        // Image::uploadImage returns null rather than throwing. Without this
        // check a failed upload would be written into the column as null and
        // reported as success.
        if ($url === null) {
            return response()->json([
                'success' => false,
                'message' => 'We could not upload that picture. Please try again.',
            ], 502);
        }

        $user->avatar = $url;
        $user->save();

        // Only after the replacement is safely stored.
        if ($previous) {
            Image::deleteImage($previous, 'avatars');
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile picture updated.',
        ] + $this->payload($user->fresh()));
    }

    /** DELETE /api/profile/avatar */
    public function destroyAvatar(Request $request)
    {
        $user = $request->user();

        if ($user->avatar) {
            Image::deleteImage($user->avatar, 'avatars');
            $user->avatar = null;
            $user->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Profile picture removed.',
        ] + $this->payload($user->fresh()));
    }

    /**
     * The whole page in one response.
     *
     * Returned from every mutation too, so the client never has to guess what
     * changed downstream - saving a name that clears phone verification, for
     * instance, comes back with the new verification state attached.
     *
     * @return array<string, mixed>
     */
    private function payload(User $user): array
    {
        $claim = $user->latestDiscountClaim()->with('verifier:id,first_name,last_name')->first();
        $isStaff = $user->hasRole(User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN);

        return [
            'user' => $user,
            'profile' => [
                // Not editable through this controller. It is a login
                // identifier and a verification channel, so changing it needs
                // proof of the new address - a flow, not a text input.
                'email_editable' => false,
                'phone_locked_reason' => $this->phoneLockReason($user),
                'privacy_consent' => $user->hasGivenPrivacyConsent(),
                'privacy_consent_at' => $user->privacy_consent_at?->toIso8601String(),
            ],
            'addresses' => $user->addresses()->orderByDesc('is_default')->orderByDesc('id')->get(),
            // Staff do not get statutory customer discounts, so the whole
            // section is absent from their payload rather than merely hidden by
            // the client.
            'discount' => $isStaff ? null : [
                'claim' => $claim,
                'is_eligible' => $claim?->isApproved() ?? false,
                'can_apply' => $claim === null || $claim->isRejected(),
                'types' => Discount::types(),
                'percentage' => Discount::currentPercentage(),
            ],
            // UI only for now - the earning/redeeming ledger is a later module.
            'loyalty_points' => (int) $user->loyalty_points,
        ];
    }

    /** Why the mobile number cannot be edited, or null when it can. */
    private function phoneLockReason(User $user): ?string
    {
        if ($user->verification_channel === Channel::Sms->value) {
            return 'Your phone is how you verify sign-ins. Verify your email address first to change it.';
        }

        if ($user->two_factor_channel === Channel::Sms->value) {
            return 'Two-step verification sends codes here. Move it to email first to change your phone.';
        }

        return null;
    }
}
