<?php

namespace App\Http\Controllers;

use App\Events\MenuBroadcast;
use App\Http\Resources\FoodResource;
use App\Jobs\NotifyEveryAccount;
use App\Models\Food;
use App\Services\Auth\PasswordConfirmation;
use App\Services\Stock\StockReservation;
use App\Utils\Image;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The menu, read by everyone, and the Store Manager's catalogue (BR-29,
 * UC-MENU-010..014).
 *
 * The writing routes sit in the `role:super_admin` group; the policy checks
 * below say the same thing a second time. Stock and availability are the Store
 * Agent's, in StockController - except that archiving a dish here is the same
 * switch (`is_available`), by the Store Manager's decision.
 */
class FoodController extends Controller implements HasMiddleware
{
    use AuthorizesRequests;

    public const MAX_PRICE = 99999;

    public const IMAGE_TYPES = 'jpg,jpeg,png,webp,gif';

    public const IMAGE_MAX_KB = 5120;

    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum', except: ['index', 'show', 'drinks', 'sides']),
        ];
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'best_seller' => ['sometimes', 'boolean'],
            'orderable' => ['sometimes', 'boolean'],
        ]);

        $category = $validated['category'] ?? null;

        $foods = Food::query()
            ->with(['category', 'addons'])
            ->withHeldQuantity()
            ->when($category !== null && $category !== '' && $category !== 'all', function ($query) use ($category) {
                is_numeric($category)
                    ? $query->where('category_id', (int) $category)
                    : $query->whereRelation('category', 'name', $category);
            })
            ->search($validated['search'] ?? null)
            ->when($request->boolean('best_seller'), fn ($query) => $query->where('is_best_seller', true))
            ->when($request->boolean('orderable'), fn ($query) => $query->orderable())
            ->orderByDesc('is_available')
            ->orderByRaw('CASE WHEN stock_quantity IS NULL THEN 1 WHEN stock_quantity > 0 THEN 1 ELSE 0 END DESC')
            ->orderByDesc('is_best_seller')
            ->orderBy('food_name')
            ->get();

        return response()->json([
            'data' => FoodResource::collection($foods),
            'meta' => FoodResource::meta(),
        ]);
    }

    /** POST /api/foods - a new dish (UC-MENU-010). Multipart: the photo is required. */
    public function store(Request $request)
    {
        $this->authorize('create', Food::class);

        $validated = $request->validate($this->rules(creating: true));
        $this->assertNameIsFree($validated['food_name']);

        $thumbnail = Image::uploadImage($request->file('thumbnail'), 'food');

        if ($thumbnail === null) {
            return $this->uploadFailed();
        }

        $food = Food::create([
            'food_name' => $validated['food_name'],
            'category_id' => $validated['category_id'],
            'description' => $validated['description'] ?? '',
            'price' => (int) $validated['price'],
            'thumbnail' => $thumbnail,
            'is_available' => $validated['is_available'] ?? true,
        ]);

        return $this->answer($food, 'created', $food->food_name.' was added to the menu.', 201);
    }

    public function show(Food $food)
    {
        return response()->json([
            'data' => new FoodResource($food->load(['category', 'addons'])),
            'meta' => FoodResource::meta(),
        ]);
    }

    /**
     * PUT|PATCH /api/foods/{food} - edit a dish (UC-MENU-011, -013, -014).
     *
     * A new price asks for the Manager's password first and, once saved, tells
     * every account (UC-NOTIF-009). Carts already float to it (BR-18); an order
     * keeps the price it was placed at. A new photo replaces the old one only
     * after it has uploaded, so a failed upload leaves the dish as it was.
     */
    public function update(Request $request, Food $food)
    {
        $this->authorize('update', $food);

        $validated = $request->validate($this->rules(creating: false));
        $this->assertNameIsFree($validated['food_name'], $food);

        $previousPrice = (int) $food->price;
        $priceChanged = (int) $validated['price'] !== $previousPrice;

        if ($priceChanged) {
            $failure = app(PasswordConfirmation::class)->challenge($request, 'change a menu price');

            if ($failure !== null) {
                return $failure;
            }
        }

        $oldThumbnail = $food->thumbnail;
        $newThumbnail = null;

        if ($request->hasFile('thumbnail')) {
            $newThumbnail = Image::uploadImage($request->file('thumbnail'), 'food');

            if ($newThumbnail === null) {
                return $this->uploadFailed();
            }
        }

        $food->fill([
            'food_name' => $validated['food_name'],
            'category_id' => $validated['category_id'],
            'description' => $validated['description'] ?? '',
            'price' => (int) $validated['price'],
        ]);

        if (array_key_exists('is_available', $validated)) {
            $food->is_available = (bool) $validated['is_available'];
        }

        if ($newThumbnail !== null) {
            $food->thumbnail = $newThumbnail;
        }

        $food->save();

        if ($newThumbnail !== null) {
            $this->forgetImage($oldThumbnail);
        }

        if ($priceChanged) {
            $this->announcePriceChange($food, $previousPrice);
        }

        return $this->answer($food, 'updated', $food->food_name.' was updated.');
    }

    /**
     * PATCH /api/foods/{food}/archive - take a dish off the menu, or put it back
     * (UC-MENU-012).
     *
     * Archiving is the availability switch the Store Agent also uses, so either
     * of them can bring it back. Nothing is deleted: carts float, and past
     * orders keep their lines.
     */
    public function archive(Request $request, Food $food, StockReservation $stock)
    {
        $this->authorize('update', $food);

        $validated = $request->validate([
            'archived' => ['required', 'boolean'],
        ]);

        $food = $stock->setAvailability($food, ! $validated['archived']);

        return response()->json([
            'message' => $food->is_available
                ? $food->food_name.' is back on the menu.'
                : $food->food_name.' was archived. Customers no longer see it on the menu.',
            'data' => new FoodResource($food->load(['category', 'addons'])),
        ]);
    }

    /**
     * DELETE /api/foods/{food} - remove a dish from the catalogue.
     *
     * A soft delete: past orders reference the row, and the database would
     * otherwise delete their lines with it. The dish leaves the catalogue, the
     * menu and every cart; its photo is kept with the row.
     */
    public function destroy(Food $food)
    {
        $this->authorize('delete', $food);

        DB::transaction(function () use ($food) {
            // A deleted dish cannot be bought, so it should not sit in a cart
            // waiting to fail at checkout.
            $food->cartItems()->delete();
            $food->delete();
        });

        $this->broadcast($food->load('category'), 'deleted');

        return response()->json([
            'message' => $food->food_name.' was deleted from the catalog.',
            'id' => $food->id,
        ]);
    }

    // Stock and availability are the Store Agent's, in StockController (BR-29).

    public function drinks(Request $request)
    {
        $drinks = Food::whereRelation('category', 'name', 'Drinks')
            ->with('category')
            ->get();

        return response()->json($drinks);
    }

    public function sides(Request $request)
    {
        $sides = Food::whereRelation('category', 'name', 'Sides')
            ->with('category')
            ->get();

        return response()->json($sides);
    }

    private function rules(bool $creating): array
    {
        return [
            'thumbnail' => [$creating ? 'required' : 'nullable', 'file', 'image', 'mimes:'.self::IMAGE_TYPES, 'max:'.self::IMAGE_MAX_KB],
            'food_name' => ['required', 'string', 'max:255'],
            // Whole pesos: the column is an integer, and a fraction would be
            // silently dropped rather than refused.
            'price' => ['required', 'integer', 'min:0', 'max:'.self::MAX_PRICE],
            'description' => ['nullable', 'string', 'max:255'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'is_available' => ['sometimes', 'boolean'],
        ];
    }

    /** Two dishes with one name would be indistinguishable on the menu and on receipts. */
    private function assertNameIsFree(string $name, ?Food $except = null): void
    {
        $taken = Food::query()
            ->whereRaw('LOWER(TRIM(food_name)) = ?', [mb_strtolower(trim($name))])
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'food_name' => 'A dish named "'.trim($name).'" is already in the catalog.',
            ]);
        }
    }

    private function answer(Food $food, string $event, string $message, int $status = 200)
    {
        $food->load(['category', 'addons']);

        $this->broadcast($food, $event);

        return response()->json([
            'message' => $message,
            'data' => new FoodResource($food),
        ], $status);
    }

    /** Saved already; a failed broadcast is logged, and pages catch up on their next read. */
    private function broadcast(Food $food, string $event): void
    {
        try {
            MenuBroadcast::dispatch($food, $event);
        } catch (Throwable $e) {
            Log::warning('Could not broadcast a menu change.', ['food' => $food->id, 'error' => $e->getMessage()]);
        }
    }

    private function announcePriceChange(Food $food, int $previous): void
    {
        $body = $food->food_name.' is now '.$this->peso((int) $food->price).', changed from '.$this->peso($previous).'.';

        try {
            NotifyEveryAccount::dispatch('Price update', $body);
        } catch (Throwable $e) {
            Log::warning('Could not announce a price change.', ['food' => $food->id, 'error' => $e->getMessage()]);
        }
    }

    private function peso(int $amount): string
    {
        return '₱'.number_format($amount);
    }

    private function forgetImage(?string $url): void
    {
        try {
            Image::deleteImage($url, 'food');
        } catch (Throwable $e) {
            Log::warning('Could not remove a replaced dish photo.', ['error' => $e->getMessage()]);
        }
    }

    private function uploadFailed()
    {
        return response()->json([
            'message' => 'The photo could not be uploaded. Nothing was saved - try again in a moment.',
            'error_code' => 'IMAGE_UPLOAD_FAILED',
        ], 502);
    }
}
