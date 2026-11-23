<?php

namespace App\Http\Controllers;

use App\Events\AddonBroadcast;
use App\Http\Resources\AddonResource;
use App\Models\Addon;
use App\Models\Food;
use App\Models\User;
use App\Services\Auth\PasswordConfirmation;
use App\Services\Catalog\AddonCarts;
use App\Services\Catalog\PriceAnnouncer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Add-ons (BR-17; decided 2026-11-24).
 *
 *   GET    /api/addons                       both staff roles
 *   POST   /api/addons                       Store Manager: a new add-on
 *   PUT    /api/addons/{addon}               Store Manager: edit it
 *   PATCH  /api/addons/{addon}/archive       Store Manager: archive or bring back
 *   DELETE /api/addons/{addon}               Store Manager: delete (past orders keep it)
 *   PATCH  /api/addons/{addon}/availability  Store Agent: switch it on or off
 *   PATCH  /api/agent/addons/availability    Store Agent: switch the ticked ones together
 *   PATCH  /api/addons/{addon}/sizes/{size}  Store Agent: switch one size on or off
 *
 * The routes sit in the role groups; the catalogue writes check the role a
 * second time, as FoodController does. Archive and the agent's availability
 * are one switch, as they are for a dish. A price change asks for the
 * password and is announced like a dish's (FR-07.8).
 */
class AddonController extends Controller
{
    use AuthorizesRequests;

    private const MESSAGES = [
        'addon_price.required_without' => 'Enter a price, or add sizes with their prices.',
        'sizes.*.size.distinct' => 'Each size can be listed once.',
    ];

    private const ATTRIBUTES = [
        'addon_name' => 'name',
        'addon_group' => 'group',
        'addon_price' => 'price',
        'sizes.*.size' => 'size',
        'sizes.*.price' => 'size price',
        'food_ids' => 'dishes',
        'food_ids.*' => 'dish',
    ];

    public function __construct(private AddonCarts $carts) {}

    public function index(Request $request)
    {
        $addons = Addon::query()
            ->with(['sizes', 'foods' => fn ($query) => $query->select('food.id')])
            ->orderBy('addon_name')
            ->get();

        $role = $request->user()?->role;

        return response()->json([
            'data' => AddonResource::collection($addons),
            'meta' => [
                'groups' => $this->groups($addons),
                'sizes' => collect(Addon::SIZES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
                'max_price' => FoodController::MAX_PRICE,
                // The catalogue is the Store Manager's; the switches the Store Agent's.
                'can_edit' => $role === User::ROLE_SUPER_ADMIN,
                'can_manage' => $role === User::ROLE_ADMIN,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('isSuperAdmin', Food::class);

        $validated = $request->validate($this->rules(), self::MESSAGES, self::ATTRIBUTES);
        $this->assertNameIsFree($validated['addon_name']);

        $addon = DB::transaction(function () use ($validated) {
            $addon = Addon::create([
                'addon_name' => trim($validated['addon_name']),
                'addon_group' => trim($validated['addon_group']),
                'addon_price' => $this->basePrice($validated),
                'availability' => $validated['is_available'] ?? true,
            ]);

            $this->syncSizes($addon, $validated['sizes'] ?? []);
            $addon->foods()->sync($validated['food_ids'] ?? []);

            return $addon;
        });

        return $this->answer($addon, 'created', $addon->addon_name.' was added.', 201);
    }

    /**
     * Prices as a list, keyed by size ('' for one without sizes). A change to
     * any price it already had, or a switch between sizes and one price, asks
     * for the password and is announced; adding or dropping a size alone is
     * neither.
     */
    public function update(Request $request, Addon $addon)
    {
        $this->authorize('isSuperAdmin', Food::class);

        $addon->load('sizes');

        $validated = $request->validate($this->rules(), self::MESSAGES, self::ATTRIBUTES);
        $this->assertNameIsFree($validated['addon_name'], $addon);

        $before = $this->priceList($addon);
        $after = $this->requestedPrices($validated);
        $priceChanged = $this->pricesMoved($before, $after);

        if ($priceChanged) {
            $failure = app(PasswordConfirmation::class)->challenge($request, 'change an add-on price');

            if ($failure !== null) {
                return $failure;
            }
        }

        $foodsBefore = $addon->foods()->pluck('food.id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($addon, $validated) {
            $addon->fill([
                'addon_name' => trim($validated['addon_name']),
                'addon_group' => trim($validated['addon_group']),
                'addon_price' => $this->basePrice($validated),
            ]);

            if (array_key_exists('is_available', $validated)) {
                $addon->availability = (bool) $validated['is_available'];
            }

            $addon->save();

            $this->syncSizes($addon, $validated['sizes'] ?? []);

            // Left alone when the form does not send it.
            if (array_key_exists('food_ids', $validated)) {
                $addon->foods()->sync($validated['food_ids'] ?? []);
            }
        });

        $addon->refresh()->load('sizes');
        $this->carts->tidy($addon);

        $foods = array_values(array_unique(array_merge($foodsBefore, $addon->foods()->pluck('food.id')->map(fn ($id) => (int) $id)->all())));

        if ($priceChanged) {
            app(PriceAnnouncer::class)->announce($this->priceNotice($addon->addon_name, $before, $after), $foods);
        }

        return $this->answer($addon, 'updated', $addon->addon_name.' was updated.', 200, $foods);
    }

    /** The Store Manager's Archive: the same switch as the agent's availability. */
    public function archive(Request $request, Addon $addon)
    {
        $this->authorize('isSuperAdmin', Food::class);

        $validated = $request->validate(['archived' => ['required', 'boolean']]);

        $addon->availability = ! $validated['archived'];
        $addon->save();

        return $this->answer(
            $addon,
            'updated',
            $addon->availability
                ? $addon->addon_name.' is back on the menu.'
                : $addon->addon_name.' was archived. Customers no longer see it.'
        );
    }

    /**
     * A soft delete: past orders keep their add-on lines. It leaves every dish
     * and every cart now.
     */
    public function destroy(Addon $addon)
    {
        $this->authorize('isSuperAdmin', Food::class);

        $foods = $addon->foods()->pluck('food.id')->map(fn ($id) => (int) $id)->all();

        DB::transaction(function () use ($addon) {
            $addon->foods()->detach();
            $addon->delete();
        });

        $this->carts->tidy($addon);
        $this->broadcast($addon, 'deleted', $foods);

        return response()->json([
            'message' => $addon->addon_name.' was deleted from the catalog.',
            'id' => $addon->id,
        ]);
    }

    /** The Store Agent's switch for the add-on. */
    public function availability(Request $request, Addon $addon)
    {
        $validated = $request->validate(['is_available' => ['required', 'boolean']]);

        $addon->availability = (bool) $validated['is_available'];
        $addon->save();

        return $this->answer(
            $addon,
            'updated',
            $addon->availability ? $addon->addon_name.' is back on the menu.' : $addon->addon_name.' is switched off.'
        );
    }

    /** PATCH /api/agent/addons/availability - the Store Agent's switch for the ticked add-ons together. */
    public function bulkAvailability(Request $request)
    {
        $validated = $request->validate([
            'addon_ids' => ['required', 'array', 'min:1', 'max:200'],
            'addon_ids.*' => ['integer', 'distinct', Rule::exists('addons', 'id')->whereNull('deleted_at')],
            'is_available' => ['required', 'boolean'],
        ]);

        $available = (bool) $validated['is_available'];

        $addons = Addon::query()
            ->with(['sizes', 'foods' => fn ($query) => $query->select('food.id')])
            ->whereKey($validated['addon_ids'])
            ->orderBy('addon_name')
            ->get();

        $changing = $addons->filter(fn (Addon $addon) => (bool) $addon->availability !== $available);

        if ($changing->isNotEmpty()) {
            Addon::query()->whereKey($changing->modelKeys())->update(['availability' => $available]);
        }

        foreach ($changing as $addon) {
            $addon->availability = $available;
            $this->broadcast($addon, 'updated', $addon->foods->pluck('id')->map(fn ($id) => (int) $id)->all());
        }

        $count = $addons->count();

        return response()->json([
            'message' => ($count === 1 ? '1 add-on' : $count.' add-ons').($available ? ' back on the menu.' : ' switched off.'),
            'data' => AddonResource::collection($addons),
        ]);
    }

    /** The Store Agent's switch for one size, when it runs out or comes back. */
    public function sizeAvailability(Request $request, Addon $addon, string $size)
    {
        $validated = $request->validate(['is_available' => ['required', 'boolean']]);

        $row = $addon->sizes()->where('size', $size)->first();

        if ($row === null) {
            return response()->json([
                'message' => $addon->addon_name.' does not come in '.strtolower((string) Addon::sizeLabel($size)).'.',
                'error_code' => 'SIZE_NOT_OFFERED',
            ], 422);
        }

        $row->is_available = (bool) $validated['is_available'];
        $row->save();

        $name = Addon::nameWith($addon->addon_name, $size);

        return $this->answer(
            $addon,
            'updated',
            $row->is_available ? $name.' is available again.' : $name.' is switched off.'
        );
    }

    private function rules(): array
    {
        $max = FoodController::MAX_PRICE;

        return [
            'addon_name' => ['required', 'string', 'max:100'],
            'addon_group' => ['required', 'string', 'max:50'],
            // Whole pesos, as a dish's price. Not needed when it comes in sizes.
            'addon_price' => ['nullable', 'required_without:sizes', 'integer', 'min:0', 'max:'.$max],
            'sizes' => ['sometimes', 'nullable', 'array', 'max:'.count(Addon::SIZES)],
            'sizes.*.size' => ['required', 'string', 'distinct', Rule::in(array_keys(Addon::SIZES))],
            'sizes.*.price' => ['required', 'integer', 'min:0', 'max:'.$max],
            'food_ids' => ['sometimes', 'nullable', 'array', 'max:500'],
            'food_ids.*' => ['integer', 'distinct', Rule::exists('food', 'id')->whereNull('deleted_at')],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }

    /** Two add-ons with one name would be indistinguishable on the dish screen and on receipts. */
    private function assertNameIsFree(string $name, ?Addon $except = null): void
    {
        $taken = Addon::query()
            ->whereRaw('LOWER(TRIM(addon_name)) = ?', [mb_strtolower(trim($name))])
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'addon_name' => 'An add-on named "'.trim($name).'" is already in the catalog.',
            ]);
        }
    }

    /** The one price, or for a sized add-on its lowest size price. */
    private function basePrice(array $validated): int
    {
        $sizes = $validated['sizes'] ?? [];

        return $sizes === []
            ? (int) $validated['addon_price']
            : (int) min(array_column($sizes, 'price'));
    }

    /**
     * Sizes as sent, each keeping the agent's switch if it was there before;
     * a size no longer sent is removed.
     *
     * @param  list<array{size: string, price: int|string}>  $sizes
     */
    private function syncSizes(Addon $addon, array $sizes): void
    {
        $keep = array_column($sizes, 'size');

        $addon->sizes()->whereNotIn('size', $keep)->delete();

        foreach ($sizes as $size) {
            $addon->sizes()->updateOrCreate(
                ['size' => $size['size']],
                ['price' => (int) $size['price']],
            );
        }

        $addon->unsetRelation('sizes');
    }

    /** @return array<string, int> keyed by size, '' for one without sizes */
    private function priceList(Addon $addon): array
    {
        if (! $addon->isSized()) {
            return ['' => (int) $addon->addon_price];
        }

        return $addon->sizes->mapWithKeys(fn ($size) => [$size->size => (int) $size->price])->all();
    }

    /** @return array<string, int> as priceList, smallest size first */
    private function requestedPrices(array $validated): array
    {
        $sizes = collect($validated['sizes'] ?? []);

        if ($sizes->isEmpty()) {
            return ['' => (int) $validated['addon_price']];
        }

        return collect(Addon::SIZES)
            ->keys()
            ->mapWithKeys(fn ($key) => [$key => $sizes->firstWhere('size', $key)['price'] ?? null])
            ->filter(fn ($price) => $price !== null)
            ->map(fn ($price) => (int) $price)
            ->all();
    }

    private function pricesMoved(array $before, array $after): bool
    {
        if (array_key_exists('', $before) !== array_key_exists('', $after)) {
            return true;
        }

        foreach ($after as $key => $price) {
            if (array_key_exists($key, $before) && $before[$key] !== $price) {
                return true;
            }
        }

        return false;
    }

    /** "Iced Tea is now ₱70, changed from ₱65.", or the new list when more moved. */
    private function priceNotice(string $name, array $before, array $after): string
    {
        $peso = fn (int $amount) => PriceAnnouncer::peso($amount);

        if (array_key_exists('', $after)) {
            return array_key_exists('', $before)
                ? $name.' is now '.$peso($after['']).', changed from '.$peso($before['']).'.'
                : $name.' is now '.$peso($after['']).'.';
        }

        $moved = array_keys(array_filter(
            $after,
            fn ($price, $key) => array_key_exists($key, $before) && $before[$key] !== $price,
            ARRAY_FILTER_USE_BOTH,
        ));

        if (count($moved) === 1 && ! array_key_exists('', $before)) {
            $key = $moved[0];

            return Addon::nameWith($name, $key).' is now '.$peso($after[$key]).', changed from '.$peso($before[$key]).'.';
        }

        $list = collect($after)->map(fn ($price, $key) => Addon::sizeLabel($key).' '.$peso($price))->values();
        $last = $list->pop();

        return $name.' is now '.($list->isEmpty() ? $last : $list->implode(', ').' and '.$last).'.';
    }

    /** @return list<string> the groups in use, with the usual two, for the form's suggestions */
    private function groups(Collection $addons): array
    {
        return $addons->pluck('addon_group')
            ->merge(['Drinks', 'Sides'])
            ->map(fn ($group) => trim((string) $group))
            ->filter()
            ->unique(fn ($group) => mb_strtolower($group))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /** @param  list<int>|null  $foods */
    private function answer(Addon $addon, string $event, string $message, int $status = 200, ?array $foods = null)
    {
        $addon->load(['sizes', 'foods' => fn ($query) => $query->select('food.id')]);

        $this->broadcast($addon, $event, $foods ?? $addon->foods->pluck('id')->map(fn ($id) => (int) $id)->all());

        return response()->json([
            'message' => $message,
            'data' => new AddonResource($addon),
        ], $status);
    }

    /** Saved already; a failed broadcast is logged, and pages catch up on their next read. */
    private function broadcast(Addon $addon, string $event, array $foods): void
    {
        try {
            AddonBroadcast::dispatch($addon, $event, $foods);
        } catch (Throwable $e) {
            Log::warning('Could not broadcast an add-on change.', ['addon' => $addon->id, 'error' => $e->getMessage()]);
        }
    }
}
