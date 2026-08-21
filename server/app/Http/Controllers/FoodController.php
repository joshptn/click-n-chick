<?php

namespace App\Http\Controllers;

use App\Events\MenuBroadcast;
use App\Http\Resources\FoodResource;
use App\Models\Food;
use App\Services\Auth\PasswordConfirmation;
use App\Utils\Image;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Log;

class FoodController extends Controller implements HasMiddleware
{
    use AuthorizesRequests;

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

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $this->authorize('isSuperAdmin', Food::class);

            $validated = $request->validate([
                'thumbnail' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif', 'max:5120'],
                'food_name' => ['required', 'string', 'max:255'],
                'price' => ['required', 'numeric', 'min:0'],
                'is_available' => ['sometimes', 'boolean'],
                'description' => ['required', 'string', 'max:255'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            ]);

            $validated['thumbnail'] = null;

            if ($request->hasFile('thumbnail')) {
                $file = $request->file('thumbnail');
                $validated['thumbnail'] = Image::uploadImage($file, 'food');
            }

            $food = Food::create($validated);

            MenuBroadcast::dispatch($food->load('category'), 'created');

            return response()->json($food->load('category'), 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'Unauthorized: only admins can perform this action',
            ], 403);
        } catch (\Exception $e) {
            Log::error('Food store error: '.$e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Food $food)
    {
        return response()->json([
            'data' => new FoodResource($food->load(['category', 'addons'])),
            'meta' => FoodResource::meta(),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Food $food)
    {
        try {
            $this->authorize('isSuperAdmin', Food::class);

            $validated = $request->validate([
                'thumbnail' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif', 'max:5120'],
                'food_name' => ['required', 'string', 'max:255'],
                'price' => ['required', 'numeric', 'min:0'],
                'is_available' => ['sometimes', 'boolean'],
                'description' => ['required', 'string', 'max:255'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            ]);

            if (round((float) $validated['price'], 2) !== round((float) $food->price, 2)) {
                $failure = app(PasswordConfirmation::class)->challenge($request, 'change a menu price');

                if ($failure !== null) {
                    return $failure;
                }
            }

            if ($request->hasFile('thumbnail')) {
                $file = $request->file('thumbnail');

                if (! $file->isValid()) {
                    return response()->json(['message' => 'Invalid file upload.'], 400);
                }

                try {

                    Image::deleteImage($food->thumbnail, 'food');
                    $validated['thumbnail'] = Image::uploadImage($file, 'food');

                } catch (\Throwable $e) {
                    Log::error('Cloudinary upload failed: '.$e->getMessage());

                    return response()->json([
                        'message' => 'Failed to upload image.',
                        'error' => $e->getMessage(),
                    ], 500);
                }
            } else {
                $validated['thumbnail'] = $food->thumbnail;
            }

            $food->update($validated);
            $food->refresh();

            MenuBroadcast::dispatch($food->load('category'), 'updated');

            return response()->json($food->load('category'), 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'Unauthorized: only admins can perform this action',
            ], 403);
        } catch (\Exception $e) {
            Log::error('Food update error: '.$e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Food $food)
    {
        try {
            $this->authorize('isSuperAdmin', Food::class);

            // Delete from Cloudinary if URL exists
            if ($food->thumbnail && str_contains($food->thumbnail, 'res.cloudinary.com')) {
                Image::deleteImage($food->thumbnail, 'food');
            }

            $foodData = $food->load('category');
            $food->delete();

            MenuBroadcast::dispatch($foodData, 'deleted');

            return response()->json(['message' => 'Food deleted successfully'], 200);

        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return response()->json([
                'message' => 'Unauthorized: only admins can perform this action',
            ], 403);
        } catch (\Exception $e) {
            Log::error('Food destroy error: '.$e->getMessage());

            return response()->json([
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Set an item's stock level. Store Agent only.
     *
     * BR-29 / FR-07.2. The gate is the route group's `role:admin`, which is an
     * exact match and therefore excludes the Store Manager. Deliberately NOT
     * guarded with authorize('isAdmin'), because that policy admits
     * super_admin and would re-open the boundary this method exists to close.
     */
    public function updateStock(Request $request, Food $food)
    {
        $validated = $request->validate([
            'stock_quantity' => ['required', 'integer', 'min:0'],
        ]);

        $food->update($validated);
        $food->refresh();

        MenuBroadcast::dispatch($food->load('category'), 'updated');

        return response()->json($food->load('category'), 200);
    }

    /**
     * Toggle whether an item can be ordered. Store Agent only.
     *
     * Same boundary as updateStock - see BR-29 note above.
     */
    public function updateAvailability(Request $request, Food $food)
    {
        $validated = $request->validate([
            'is_available' => ['required', 'boolean'],
        ]);

        $food->update($validated);
        $food->refresh();

        MenuBroadcast::dispatch($food->load('category'), 'updated');

        return response()->json($food->load('category'), 200);
    }

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
}
