<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account as User Management shows it. A whitelist on purpose: no password,
 * token or 2FA secret can reach it, and the phone number is only ever masked.
 *
 * `can` is the server's answer to what the viewer may do to this account, and
 * `discount` comes from an approved claim alone.
 */
class ManagedUserResource extends JsonResource
{
    private bool $detailed = false;

    public function detailed(): self
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray($request): array
    {
        $isSelf = (int) $request->user()?->getKey() === (int) $this->id;
        $settled = ! $this->resource->isPendingVerification();
        $suspended = $this->resource->isSuspended();
        $approved = $this->relationLoaded('approvedDiscount') ? $this->approvedDiscount : null;

        $row = [
            'id' => $this->id,
            'name' => trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: null,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->email,
            'phone_masked' => $this->maskedPhone(),
            'avatar' => $this->avatar,
            'role' => $this->role,
            'status' => $this->displayStatus(),
            'discount' => $approved ? ['type' => $approved->discount_type] : null,
            'joined_at' => $this->created_at?->toIso8601String(),
            'is_self' => $isSelf,
            'can' => [
                'edit' => $settled,
                'change_role' => ! $isSelf && $settled && ! $suspended,
                'suspend' => ! $isSelf && $settled && ! $suspended,
                'reactivate' => ! $isSelf && $suspended,
                'delete' => ! $isSelf,
            ],
        ];

        if (! $this->detailed) {
            return $row;
        }

        $claim = $this->latestDiscountClaim;

        return $row + [
            'verification' => [
                'channel' => $this->verification_channel,
                'email_verified' => $this->email_verified_at !== null,
                'phone_verified' => $this->phone_verified_at !== null,
            ],
            'two_factor_enabled' => $this->resource->hasTwoFactorEnabled(),
            'orders_count' => (int) $this->orders_count,
            'active_sessions' => (int) $this->tokens_count,
            'last_seen_at' => $this->knownDevices()->max('last_seen_at'),
            'latest_claim' => $claim ? [
                'type' => $claim->discount_type,
                'status' => $claim->discount_status,
            ] : null,
        ];
    }

    /** active | suspended | unverified: what the account can do at sign-in today. */
    private function displayStatus(): string
    {
        return match (true) {
            $this->resource->isSuspended() => User::STATUS_SUSPENDED,
            $this->resource->isPendingVerification(), ! $this->resource->hasVerifiedChosenChannel() => 'unverified',
            default => User::STATUS_ACTIVE,
        };
    }

    private function maskedPhone(): ?string
    {
        $phone = $this->phone_number;

        if (! is_string($phone) || strlen($phone) < 9) {
            return null;
        }

        return substr($phone, 0, 4).str_repeat('*', strlen($phone) - 6).substr($phone, -2);
    }
}
