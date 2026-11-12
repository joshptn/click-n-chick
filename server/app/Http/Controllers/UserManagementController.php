<?php

namespace App\Http\Controllers;

use App\Events\SessionRevoked;
use App\Http\Resources\ManagedUserResource;
use App\Models\AuthEvent;
use App\Models\Order;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\Orders\OrderStatus;
use App\Services\Verification\Channel;
use App\Services\Verification\ChannelRegistry;
use App\Utils\Image;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * User Management (UC-ADMIN-001, 002). Store Manager only: the routes sit in the
 * `role:super_admin` group, and the privileged writes also pass `confirm-password`.
 */
class UserManagementController extends Controller
{
    use AuthorizesRequests;

    private const PER_PAGE = 20;

    private const STATUSES = ['all', User::STATUS_ACTIVE, User::STATUS_SUSPENDED, 'unverified'];

    /** GET /api/admin/users */
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'string', Rule::in(['all', ...User::ROLES])],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'discount' => ['sometimes', 'string', Rule::in(['all', 'eligible'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $query = User::query()->realAccounts()->with('approvedDiscount')->latest('id');
        $role = $validated['role'] ?? 'all';

        $query
            ->when($role !== 'all', fn (Builder $query) => $query->where('role', $role))
            ->when(($validated['discount'] ?? 'all') === 'eligible', fn (Builder $query) => $query->whereHas('approvedDiscount'));

        $this->filterStatus($query, $validated['status'] ?? 'all');
        $this->search($query, (string) ($validated['search'] ?? ''));

        $users = $query->paginate((int) ($validated['per_page'] ?? self::PER_PAGE));

        return response()->json([
            'data' => ManagedUserResource::collection($users->items()),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    /** GET /api/admin/users/{user} */
    public function show(User $user)
    {
        $this->authorize('view', $user);

        return response()->json(['data' => $this->detail($user)]);
    }

    /**
     * POST /api/admin/users
     *
     * One identifier, a phone number or an email address, as at public sign-up;
     * the code at first sign-in goes to it. `active` with nothing verified:
     * login demands verification on the timestamp, not the status.
     *
     * The new password is `new_password` because `password` is the Manager's own
     * step-up field.
     */
    public function store(Request $request, ChannelRegistry $channels)
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'identifier' => ['required', 'string', 'max:255'],
            'new_password' => ['required', 'string', 'confirmed', new StrongPassword],
            'role' => ['required', 'string', Rule::in(User::ROLES)],
        ]);

        $channel = Channel::forIdentifier($validated['identifier']);
        $transport = $channel === null ? null : $channels->for($channel);
        $identifier = $transport?->normalize($validated['identifier']);

        if ($identifier === null) {
            throw ValidationException::withMessages(['identifier' => 'Enter a valid Philippine mobile number or email address.']);
        }

        if (! $transport->isAvailable()) {
            throw ValidationException::withMessages(['identifier' => $transport->unavailableReason()]);
        }

        if ($transport->findUser($identifier) !== null) {
            throw ValidationException::withMessages([
                'identifier' => $channel === Channel::Email
                    ? 'This email address is already registered.'
                    : 'This phone number is already registered.',
            ]);
        }

        // Set one by one: role and status are what this endpoint exists to
        // decide, and must never come from a mass-assigned payload.
        $user = new User;
        $user->first_name = $validated['first_name'];
        $user->last_name = $validated['last_name'];
        $user->password = $validated['new_password'];
        $user->role = $validated['role'];
        $user->verification_channel = $channel->value;
        $user->account_status = User::STATUS_ACTIVE;

        if ($channel === Channel::Email) {
            $user->email = $identifier;
        } else {
            $user->phone_number = $identifier;
            $user->phone_number_hash = User::hashPhoneNumber($identifier);
        }

        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Account created. They verify their '.($channel === Channel::Email ? 'email' : 'phone').' at first sign-in.',
            'data' => $this->detail($user),
        ], 201);
    }

    /** PATCH /api/admin/users/{user}/profile - a name correction only. */
    public function updateProfile(Request $request, User $user)
    {
        $this->authorize('update', $user);

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
        ]);

        $user->first_name = $validated['first_name'];
        $user->last_name = $validated['last_name'];
        $user->save();

        return response()->json(['success' => true, 'message' => 'Name updated.', 'data' => $this->detail($user)]);
    }

    /** PATCH /api/admin/users/{user} */
    public function updateRole(Request $request, User $user)
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(User::ROLES)],
        ]);

        if ($this->isSelf($request, $user)) {
            return $this->refuse('You cannot change your own role.', 'SELF_ACTION');
        }

        $this->authorize('updateRole', $user);

        $result = DB::transaction(function () use ($user, $validated) {
            $target = $this->locked($user);

            if ($target->isSuspended()) {
                return $this->refuse('Reactivate this account before changing its role.', 'ACCOUNT_SUSPENDED');
            }

            if ($target->role === $validated['role']) {
                return ['target' => $target, 'devices' => collect()];
            }

            if ($this->isLastManager($target)) {
                return $this->refuse('Cannot remove the last active Store Manager.', 'LAST_MANAGER');
            }

            $target->role = $validated['role'];
            $target->save();

            // A session carries the access and lifetime of the role it began with.
            return ['target' => $target, 'devices' => $this->revokeSessions($target)];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $this->announceRevoked($result['target'], $result['devices']);

        return response()->json([
            'success' => true,
            'message' => 'Role updated successfully.',
            'data' => $this->detail($result['target']),
        ]);
    }

    /** POST /api/admin/users/{user}/suspend */
    public function suspend(Request $request, User $user)
    {
        if ($this->isSelf($request, $user)) {
            return $this->refuse('You cannot suspend your own account.', 'SELF_ACTION');
        }

        $this->authorize('suspend', $user);

        $result = DB::transaction(function () use ($user) {
            $target = $this->locked($user);

            if ($target->isSuspended()) {
                return $this->refuse('This account is already suspended.', 'ALREADY_SUSPENDED');
            }

            if ($this->isLastManager($target)) {
                return $this->refuse('Cannot suspend the last active Store Manager.', 'LAST_MANAGER');
            }

            $target->account_status = User::STATUS_SUSPENDED;
            $target->save();

            return ['target' => $target, 'devices' => $this->revokeSessions($target)];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        $this->announceRevoked($result['target'], $result['devices']);

        return response()->json([
            'success' => true,
            'message' => 'Account suspended and signed out everywhere.',
            'data' => $this->detail($result['target']),
        ]);
    }

    /** POST /api/admin/users/{user}/reactivate */
    public function reactivate(Request $request, User $user)
    {
        if ($this->isSelf($request, $user)) {
            return $this->refuse('You cannot change your own account status.', 'SELF_ACTION');
        }

        $this->authorize('suspend', $user);

        if (! $user->isSuspended()) {
            return $this->refuse('This account is not suspended.', 'NOT_SUSPENDED');
        }

        $user->account_status = User::STATUS_ACTIVE;
        $user->save();

        return response()->json(['success' => true, 'message' => 'Account reactivated.', 'data' => $this->detail($user)]);
    }

    /**
     * DELETE /api/admin/users/{user}
     *
     * The account row and its personal data are erased; the store's orders and
     * payments stay. orders.user_id cascades, so the orders are detached first
     * and keep only the placeholder name. What was sold, for how much, and the
     * address snapshot remain on each order. Orders this person cancelled for
     * the store keep counting as the store's, under an anonymous name.
     */
    public function destroy(Request $request, User $user)
    {
        if ($this->isSelf($request, $user)) {
            return $this->refuse('You cannot delete your own account.', 'SELF_ACTION');
        }

        $this->authorize('delete', $user);

        $result = DB::transaction(function () use ($user) {
            $target = $this->locked($user);

            if ($this->isLastManager($target)) {
                return $this->refuse('Cannot delete the last active Store Manager.', 'LAST_MANAGER');
            }

            if ($target->Orders()->whereNotIn('status', OrderStatus::terminal())->exists()) {
                return $this->refuse('This customer still has an open order. Delete the account once it has closed.', 'HAS_OPEN_ORDERS');
            }

            // Before the orders are detached, while "someone else's order" can
            // still be told apart from a customer cancelling their own.
            // Through the base query: updated_at dates older rows in the reports.
            $cancelledForStore = Order::query()
                ->where('cancelled_by', $target->getKey())
                ->where(fn (Builder $query) => $query->whereNull('user_id')->orWhere('user_id', '!=', $target->getKey()));

            if ($cancelledForStore->exists()) {
                $cancelledForStore->toBase()->update(['cancelled_by' => User::deletedStaff()->getKey()]);
            }

            Order::query()->where('user_id', $target->getKey())->toBase()->update([
                'user_id' => null,
                'guest_name' => Order::ANONYMIZED_CUSTOMER,
                'guest_phone' => null,
                'guest_email' => null,
            ]);

            // Would otherwise survive with this person's IP and browser on them.
            AuthEvent::query()->where('user_id', $target->getKey())->delete();

            $images = [
                'discount-ids' => $target->discounts()->pluck('id_image')->all(),
                'avatars' => [$target->avatar],
            ];
            $devices = $this->revokeSessions($target);

            $target->delete();

            return ['target' => $target, 'devices' => $devices, 'images' => $images];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        foreach ($result['images'] as $folder => $urls) {
            foreach (array_filter($urls) as $url) {
                Image::deleteImage($url, $folder);
            }
        }

        $this->announceRevoked($result['target'], $result['devices']);

        return response()->json(['success' => true, 'message' => 'Account deleted. Its orders were kept without personal details.']);
    }

    private function detail(User $user): ManagedUserResource
    {
        $user->loadCount(['orders', 'tokens'])->load(['approvedDiscount', 'latestDiscountClaim']);

        return (new ManagedUserResource($user))->detailed();
    }

    private function locked(User $user): User
    {
        return User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
    }

    private function isSelf(Request $request, User $user): bool
    {
        return (int) $request->user()->getKey() === (int) $user->getKey();
    }

    private function refuse(string $message, string $code): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error_code' => $code], 422);
    }

    /**
     * Whether no other Manager could sign in if this one went. An account that
     * has never verified a channel cannot, so it does not count. Locked so two
     * simultaneous changes cannot both see a survivor.
     */
    private function isLastManager(User $target): bool
    {
        return $target->hasRole(User::ROLE_SUPER_ADMIN) && User::query()
            ->where('role', User::ROLE_SUPER_ADMIN)
            ->where('account_status', User::STATUS_ACTIVE)
            ->where(fn (Builder $query) => $query->whereNotNull('email_verified_at')->orWhereNotNull('phone_verified_at'))
            ->whereKeyNot($target->getKey())
            ->lockForUpdate()
            ->pluck('id')
            ->isEmpty();
    }

    /** @return \Illuminate\Support\Collection<int, int> the devices that lost a session */
    private function revokeSessions(User $user)
    {
        $devices = $user->tokens()->whereNotNull('known_device_id')->pluck('known_device_id')->unique()->values();

        $user->tokens()->delete();

        return $devices;
    }

    /** A courtesy so open browsers leave at once; the tokens are already dead. */
    private function announceRevoked(User $user, $devices): void
    {
        foreach ($devices as $device) {
            try {
                SessionRevoked::dispatch((int) $user->getKey(), (int) $device);
            } catch (Throwable $e) {
                Log::warning('Could not announce a revoked session.', ['error' => $e->getMessage()]);
            }
        }
    }

    private function filterStatus(Builder $query, string $status): void
    {
        match ($status) {
            User::STATUS_SUSPENDED => $query->where('account_status', User::STATUS_SUSPENDED),
            User::STATUS_ACTIVE => $query->signInReady(),
            'unverified' => $query->awaitingVerification(),
            default => null,
        };
    }

    private function search(Builder $query, string $raw): void
    {
        $term = trim(str_replace(['%', '_'], ' ', $raw));

        if ($term === '') {
            return;
        }

        $needle = '%'.mb_strtolower($term).'%';
        $phoneHash = strlen((string) preg_replace('/\D/', '', $term)) >= 10 ? User::hashPhoneNumber($term) : null;

        $query->where(function (Builder $where) use ($term, $needle, $phoneHash) {
            $where
                ->whereRaw("LOWER(COALESCE(first_name, '') || ' ' || COALESCE(last_name, '')) LIKE ?", [$needle])
                ->orWhereRaw('LOWER(email) LIKE ?', [$needle]);

            if (preg_match('/^#?(\d{1,9})$/', $term, $match)) {
                $where->orWhere('id', (int) $match[1]);
            }

            if ($phoneHash !== null) {
                $where->orWhere('phone_number_hash', $phoneHash);
            }
        });
    }

    /** Whole-store counts, unaffected by the filters. */
    private function summary(): array
    {
        $accounts = fn () => User::query()->realAccounts();
        $roles = $accounts()->selectRaw('role, COUNT(*) AS total')->groupBy('role')->pluck('total', 'role');

        return [
            'total' => (int) $roles->sum(),
            'active' => $accounts()->signInReady()->count(),
            'suspended' => $accounts()->where('account_status', User::STATUS_SUSPENDED)->count(),
            'unverified' => $accounts()->awaitingVerification()->count(),
            'store_managers' => (int) ($roles[User::ROLE_SUPER_ADMIN] ?? 0),
            'store_agents' => (int) ($roles[User::ROLE_ADMIN] ?? 0),
            'customers' => (int) ($roles[User::ROLE_CUSTOMER] ?? 0),
            'discount_eligible' => $accounts()->whereHas('approvedDiscount')->count(),
        ];
    }
}
