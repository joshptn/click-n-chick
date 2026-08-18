<?php

namespace App\Http\Controllers;

use App\Models\Address;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Delivery addresses (UC-PROF, "Manage delivery addresses").
 *
 * Ownership is structural: every lookup starts from $request->user()
 * ->addresses(), so another account's address is not something this controller
 * can reach. A miss 404s rather than 403s, so an id belonging to someone else
 * is not confirmed to exist.
 *
 * Coordinates are accepted but not collected by the UI yet - map picking is a
 * later module. The columns are already there, so the API takes them now
 * rather than needing a shape change when it lands.
 */
class AddressController extends Controller
{
    private const LABELS = ['home', 'work', 'other'];

    /** GET /api/addresses */
    public function index(Request $request)
    {
        return response()->json([
            'addresses' => $this->all($request),
        ]);
    }

    /** POST /api/addresses */
    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $user = $request->user();

        $address = DB::transaction(function () use ($user, $validated) {
            // The first address saved is the default, whatever the request
            // said: an account with addresses but no default has no sensible
            // pick at checkout.
            $isFirst = ! $user->addresses()->exists();
            $shouldDefault = $isFirst || ($validated['is_default'] ?? false);

            if ($shouldDefault) {
                $user->addresses()->update(['is_default' => false]);
            }

            return $user->addresses()->create($validated + ['is_default' => $shouldDefault]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Address saved.',
            'address' => $address,
            'addresses' => $this->all($request),
        ], 201);
    }

    /** PUT /api/addresses/{address} */
    public function update(Request $request, string $address)
    {
        $target = $request->user()->addresses()->find($address);

        if ($target === null) {
            return $this->notFound();
        }

        $validated = $this->validated($request);

        DB::transaction(function () use ($request, $target, $validated) {
            if ($validated['is_default'] ?? false) {
                $request->user()->addresses()->whereKeyNot($target->getKey())->update(['is_default' => false]);
            }

            $target->fill($validated)->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Address updated.',
            'address' => $target->fresh(),
            'addresses' => $this->all($request),
        ]);
    }

    /** DELETE /api/addresses/{address} */
    public function destroy(Request $request, string $address)
    {
        $user = $request->user();
        $target = $user->addresses()->find($address);

        if ($target === null) {
            return $this->notFound();
        }

        DB::transaction(function () use ($user, $target) {
            $wasDefault = $target->is_default;
            $target->delete();

            // Promote another address rather than leaving the account with
            // several addresses and no default.
            if ($wasDefault) {
                $next = $user->addresses()->orderByDesc('id')->first();
                $next?->forceFill(['is_default' => true])->save();
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Address removed.',
            'addresses' => $this->all($request),
        ]);
    }

    /** PATCH /api/addresses/{address}/default */
    public function setDefault(Request $request, string $address)
    {
        $user = $request->user();
        $target = $user->addresses()->find($address);

        if ($target === null) {
            return $this->notFound();
        }

        DB::transaction(function () use ($user, $target) {
            $user->addresses()->update(['is_default' => false]);
            $target->forceFill(['is_default' => true])->save();
        });

        return response()->json([
            'success' => true,
            'message' => 'Default address updated.',
            'addresses' => $this->all($request),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', Rule::in(self::LABELS)],
            'full_address' => ['required', 'string', 'max:500'],
            'delivery_note' => ['nullable', 'string', 'max:255'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            // Accepted now, populated by the map module later.
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    private function all(Request $request)
    {
        return $request->user()->addresses()->orderByDesc('is_default')->orderByDesc('id')->get();
    }

    private function notFound()
    {
        return response()->json([
            'success' => false,
            'message' => 'Address not found.',
        ], 404);
    }
}
