<?php

namespace App\Http\Controllers;

use App\Models\Poster;
use App\Services\Media\CloudinaryService;
use Illuminate\Http\Request;
use RuntimeException;

class PosterController extends Controller
{
    public function __construct(private CloudinaryService $cloudinary)
    {
    }

    private const IMAGE_FOLDER = 'posters';

    public function index()
    {
        return response()->json([
            'data' => Poster::visible()->get()->map(fn (Poster $poster) => $this->present($poster)),
        ]);
    }

    public function all()
    {
        return response()->json([
            'data' => Poster::orderBy('sort_order')->orderByDesc('id')->get()
                ->map(fn (Poster $poster) => $this->present($poster)),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'poster_name' => ['required', 'string', 'max:255'],
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:today'],
        ]);

        try {
            $url = $this->cloudinary->upload($request->file('image'), self::IMAGE_FOLDER);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => 'The poster image could not be uploaded.',
                'error' => $e->getMessage(),
            ], 422);
        }

        $poster = Poster::create([
            'created_by' => $request->user()?->id,
            'poster_name' => $validated['poster_name'],
            'image' => $url,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json(['data' => $this->present($poster)], 201);
    }

    public function update(Request $request, Poster $poster)
    {
        $validated = $request->validate([
            'poster_name' => ['sometimes', 'string', 'max:255'],
            'image' => ['sometimes', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:5120'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
        ]);

        if ($request->hasFile('image')) {
            try {
                $url = $this->cloudinary->upload($request->file('image'), self::IMAGE_FOLDER);
            } catch (RuntimeException $e) {
                return response()->json([
                    'message' => 'The poster image could not be uploaded.',
                    'error' => $e->getMessage(),
                ], 422);
            }

            $this->cloudinary->delete($poster->image, self::IMAGE_FOLDER);
            $validated['image'] = $url;
        }

        $poster->update($validated);

        return response()->json(['data' => $this->present($poster->fresh())]);
    }

    public function destroy(Poster $poster)
    {
        $this->cloudinary->delete($poster->image, self::IMAGE_FOLDER);
        $poster->delete();

        return response()->json(['message' => 'Poster removed.']);
    }

    private function present(Poster $poster): array
    {
        return [
            'id' => $poster->id,
            'poster_name' => $poster->poster_name,
            'image' => $poster->image,
            'is_active' => $poster->is_active,
            'sort_order' => $poster->sort_order,
            'expires_at' => $poster->expires_at?->toDateString(),
        ];
    }
}
