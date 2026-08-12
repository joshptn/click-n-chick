<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Food;
use App\Utils\Image;
use App\Events\MenuBroadcast;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FoodController extends Controller implements HasMiddleware
{
    use AuthorizesRequests;

    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum', except: ['index', 'show', 'drinks', 'sides'])
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $foods = Food::with('category')
            ->get()
            ->sortBy(fn ($food) => $food->category->name ?? '')
            ->values();

        return response()->json($foods);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $this->authorize('isAdmin', Food::class);

            $validated = $request->validate([
                'thumbnail'   => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif', 'max:5120'],
                'food_name'   => ['required', 'string', 'max:255'],
                'price'       => ['required', 'numeric', 'min:0'],
                'available'   => ['required', 'boolean'],
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
            Log::error('Food store error: ' . $e->getMessage());
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
        return $food->load('category');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Food $food)
    {
        try {
            $this->authorize('isAdmin', Food::class);
            
            $validated = $request->validate([
                'thumbnail'   => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif', 'max:5120'], 
                'food_name'   => ['required', 'string', 'max:255'],
                'price'       => ['required', 'numeric', 'min:0'], 
                'available'   => ['required', 'boolean'], 
                'description' => ['required', 'string', 'max:255'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            ]);

            if ($request->hasFile('thumbnail')) {
                $file = $request->file('thumbnail');

                if (!$file->isValid()) {
                    return response()->json(['message' => 'Invalid file upload.'], 400);
                }

                try {
                    
                    Image::deleteImage($food->thumbnail, 'foods');

                    // // Upload new image
                    // $result = $cloudinary->uploadApi()->upload($file->getRealPath(), [
                    //     'folder' => 'foods',
                    // ]);

                    $validated['thumbnail'] = Image::uploadImage($file, 'foods');

                } catch (\Throwable $e) {
                    Log::error('Cloudinary upload failed: ' . $e->getMessage());
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
            Log::error('Food update error: ' . $e->getMessage());
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
            $this->authorize('isAdmin', Food::class);

            // Delete from Cloudinary if URL exists
            if ($food->thumbnail && str_contains($food->thumbnail, 'res.cloudinary.com')) {
                Image::deleteImage($food->thumbnail, 'foods');
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
            Log::error('Food destroy error: ' . $e->getMessage());
            return response()->json([
                'message' => 'An unexpected error occurred.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function drinks(Request $request)
    {
        // Was: foods sitting in BOTH 'Drinks' and 'Addons'. A food now has a
        // single category, so this matches on 'Drinks' alone.
        $drinks = Food::whereRelation('category', 'name', 'Drinks')
            ->with('category')
            ->get();

        return response()->json($drinks);
    }

    public function sides(Request $request)
    {
        // Was: foods sitting in BOTH 'Sides' and 'Addons'. A food now has a
        // single category, so this matches on 'Sides' alone.
        $sides = Food::whereRelation('category', 'name', 'Sides')
            ->with('category')
            ->get();

        return response()->json($sides);
    }
}