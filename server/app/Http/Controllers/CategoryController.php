<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Food;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;

/**
 * Menu categories: read by everyone, managed by the Store Manager
 * (UC-MENU-007..009). The writing routes sit in the `role:super_admin` group.
 */
class CategoryController extends Controller implements HasMiddleware
{
    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum', except: ['index', 'show']),
        ];
    }

    public function index()
    {
        // Alphabetical, with 'Addons' last.
        $categories = Category::where('name', '!=', 'Addons')
            ->orderBy('name', 'asc')
            ->get();

        $addons = Category::where('name', 'Addons')->get();

        return response()->json($categories->concat($addons));
    }

    /** POST /api/category */
    public function store(Request $request)
    {
        $name = $this->validName($request);

        $category = Category::create(['name' => $name]);

        return response()->json($category, 201);
    }

    public function show(Category $category)
    {
        return response()->json($category);
    }

    /** PUT|PATCH /api/category/{category} - a rename. */
    public function update(Request $request, Category $category)
    {
        $category->update(['name' => $this->validName($request, $category)]);

        return response()->json($category);
    }

    /**
     * DELETE /api/category/{category}
     *
     * Only an empty category goes. The column would otherwise null itself on
     * every dish in it, leaving them uncategorised on the menu. Archived dishes
     * count - they are still the catalogue's - deleted ones do not.
     */
    public function destroy(Category $category)
    {
        $dishes = Food::query()->where('category_id', $category->getKey())->count();

        if ($dishes > 0) {
            return response()->json([
                'message' => $category->name.' still has '.$dishes.' '.($dishes === 1 ? 'dish' : 'dishes')
                    .'. Move or delete them first.',
                'error_code' => 'CATEGORY_NOT_EMPTY',
                'dishes' => $dishes,
            ], 409);
        }

        $category->delete();

        return response()->json(['message' => $category->name.' was deleted.']);
    }

    /** Trimmed, and not a second spelling of a name that already exists. */
    private function validName(Request $request, ?Category $except = null): string
    {
        $request->validate([
            'name' => ['required', 'string', 'max:60'],
        ]);

        $name = preg_replace('/\s+/', ' ', trim((string) $request->input('name')));

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter a category name.']);
        }

        $taken = Category::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'There is already a category called "'.$name.'".']);
        }

        return $name;
    }
}
