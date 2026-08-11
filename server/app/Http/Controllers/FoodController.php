<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Food;
use App\Utils\Image;
use App\Utils\Websocket;
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
        $foods = Food::with(['categories' => function($query) {
            $query->orderBy('name', 'asc');
        }])
        ->get()
        ->sortBy(function($food) {
            if (!$food->categories || $food->categories->isEmpty()) {
                return '';
            }
            return $food->categories->first()->name ?? '';
        })
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
                'categories'  => ['array', 'nullable'],
            ]);

            $validated['thumbnail'] = null;

            if ($request->hasFile('thumbnail')) {
                $file = $request->file('thumbnail');
                $validated['thumbnail'] = Image::uploadImage($file, 'food');
            }

            $food = Food::create($validated);

            if (!empty($validated['categories'])) {
                $food->categories()->sync($validated['categories']);
            }

            Websocket::broadcast('food', 'created', $food->load('categories'));

            return response()->json($food->load('categories'), 201);

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
        return $food->load('categories');
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
                'categories'  => ['array', 'nullable'], 
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

            if (isset($validated['categories'])) {
                $food->categories()->sync($validated['categories']);
            } else {
                $food->categories()->sync([]);
            }

            Websocket::broadcast('food', 'updated', $food->load('categories'));

            return response()->json($food->load('categories'), 200);

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

            $foodData = $food->load('categories');
            $food->delete();
            
            Websocket::broadcast('food', 'deleted', $foodData);

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
        $drinksAndAddons = Food::whereHas('categories', function ($query) {
                $query->whereIn('name', ['Drinks', 'Addons']);
            }, '=', 2) 
            ->with('categories')
            ->get();

        return response()->json($drinksAndAddons);
    }

    public function sides(Request $request)
    {
        $sides = Food::whereHas('categories', function ($query) {
                $query->whereIn('name', ['Sides', 'Addons']);
            }, '=', 2) 
        ->with('categories')
        ->get();

        return response()->json($sides);
    }
}