<?php

namespace App\Http\Controllers;

use App\Models\Poster;
use App\Services\Media\CloudinaryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Homepage posters (decided 2026-11-26). The Store Manager adds, edits, turns
 * off and deletes them in Store Settings; customers only see them. None of it
 * notifies anyone; the one notice is the expiry warning the day before
 * (posters:warn-expiring).
 */
class PosterController extends Controller
{
    public function __construct(private CloudinaryService $cloudinary) {}

    private const IMAGE_FOLDER = 'posters';

    public function index()
    {
        return response()->json([
            'data' => Poster::visible()->get()->map(fn (Poster $poster) => [
                'id' => $poster->id,
                'image' => $poster->image,
            ]),
        ]);
    }

    public function all()
    {
        return response()->json([
            'data' => Poster::orderBy('id')->get()->map(fn (Poster $poster) => $this->present($poster)),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'image' => ['required', ...$this->imageRules()],
            'is_active' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', ...$this->expiryRules()],
        ], $this->messages());

        try {
            $url = $this->cloudinary->upload($request->file('image'), self::IMAGE_FOLDER);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => 'The poster image could not be uploaded.',
                'error' => $e->getMessage(),
            ], 422);
        }

        $poster = Poster::create([
            'image' => $url,
            'is_active' => $validated['is_active'] ?? true,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json(['data' => $this->present($poster)], 201);
    }

    public function update(Request $request, Poster $poster)
    {
        $validated = $request->validate([
            'image' => ['sometimes', ...$this->imageRules()],
            'is_active' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', ...$this->expiryRules()],
        ], $this->messages());

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

        return response()->json(['message' => 'Poster deleted.']);
    }

    /** JPG, PNG or WebP, at most 2 MB, already cropped to 3:1 and no smaller than the banner. */
    private function imageRules(): array
    {
        return [
            'file',
            'mimes:jpg,jpeg,png,webp',
            'max:'.Poster::MAX_KB,
            Rule::dimensions()
                ->minWidth(Poster::MIN_WIDTH)
                ->minHeight(Poster::MIN_HEIGHT)
                ->ratio(Poster::RATIO),
        ];
    }

    /** Never a date already past, when adding or editing: today is the earliest. */
    private function expiryRules(): array
    {
        return ['date_format:Y-m-d', 'after_or_equal:'.Poster::storeToday()];
    }

    private function messages(): array
    {
        return [
            'image.max' => 'The poster image must be 2 MB or smaller.',
            'image.mimes' => 'The poster image must be a JPG, PNG or WebP file.',
            'image.dimensions' => sprintf(
                'The poster must be cropped to 3:1 and be at least %d × %d pixels.',
                Poster::MIN_WIDTH,
                Poster::MIN_HEIGHT,
            ),
            'expires_at.after_or_equal' => 'The expiry date cannot be in the past.',
        ];
    }

    private function present(Poster $poster): array
    {
        return [
            'id' => $poster->id,
            'image' => $poster->image,
            'is_active' => $poster->is_active,
            'expires_at' => $poster->expires_at?->toDateString(),
            'is_expired' => $poster->isExpired(),
        ];
    }
}
