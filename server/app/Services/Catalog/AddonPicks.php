<?php

namespace App\Services\Catalog;

use App\Models\Addon;
use App\Models\Food;
use Illuminate\Support\Collection;

/**
 * The add-ons a customer picks on a line, each with the size picked for it
 * (decided 2026-11-24), as the cart, the checkout quotes and the order read
 * them.
 */
final class AddonPicks
{
    public const ERROR_CODE = 'ADDON_UNAVAILABLE';

    /**
     * What a client asked for on a dish, as [addon id => size].
     *
     * Add-ons the dish does not offer are left off, as they always were. One
     * that cannot be had today - switched off, or its size switched off - is
     * refused with the reason rather than quietly dropped. An advance order is
     * for another day, so today's switches do not apply to it.
     *
     * A sized add-on sent without a size (an app from before sizes) takes its
     * smallest available size; a size sent for an add-on without sizes is
     * dropped.
     *
     * @param  array<int, string|null>  $requested  add-on id => size or null
     * @return array{0: array<int, string|null>, 1: string|null} the picks, and why one was refused
     */
    public static function resolve(Food $food, array $requested, bool $isAdvance): array
    {
        if ($requested === []) {
            return [[], null];
        }

        $addons = $food->addons()
            ->whereIn('addons.id', array_keys($requested))
            ->with('sizes')
            ->get();

        $picks = [];

        foreach ($addons as $addon) {
            $asked = $requested[$addon->id] ?? null;

            if (! $addon->isSized()) {
                if (! $isAdvance && ! $addon->availability) {
                    return [[], $addon->addon_name.' is not available right now. Leave it off, or pick another.'];
                }

                $picks[(int) $addon->id] = null;

                continue;
            }

            $size = $asked ?? ($isAdvance ? $addon->sizes->first()?->size : $addon->firstAvailableSize());

            if ($size === null || $addon->sizeRow($size) === null) {
                return [[], $asked === null
                    ? $addon->addon_name.' is not available right now. Leave it off, or pick another.'
                    : $addon->addon_name.' does not come in '.strtolower((string) Addon::sizeLabel($asked)).'. Pick another size.'];
            }

            if (! $isAdvance && ! $addon->isAvailableIn($size)) {
                return [[], Addon::nameWith($addon->addon_name, $size).' is not available right now. Pick another size, or leave it off.'];
            }

            $picks[(int) $addon->id] = $size;
        }

        ksort($picks);

        return [$picks, null];
    }

    /**
     * A line's add-ons as one comparable key, so the same dish with the same
     * picks merges into one line.
     *
     * @param  array<int, string|null>  $picks
     */
    public static function key(array $picks): string
    {
        ksort($picks);

        return collect($picks)->map(fn ($size, $id) => $id.':'.($size ?? ''))->implode(',');
    }

    /** @param  Collection<int, Addon>  $addons  loaded through a line */
    public static function keyOf(Collection $addons): string
    {
        return self::key($addons->mapWithKeys(fn (Addon $addon) => [(int) $addon->id => $addon->pickedSize()])->all());
    }

    /**
     * One add-on as picked on a cart line: named with its size, priced at what
     * that size costs today, and whether it can still be had.
     *
     * `addon_name` carries the size ("Iced Tea (Large)") so a screen that
     * knows nothing of sizes still shows it; `name` is the add-on alone.
     *
     * @return array{id: int, addon_name: string, name: string, size: string|null, size_label: string|null, addon_price: float, is_available: bool}
     */
    public static function describe(Addon $addon): array
    {
        $size = $addon->pickedSize();

        return [
            'id' => (int) $addon->id,
            'addon_name' => $addon->chosen_name,
            'name' => (string) $addon->addon_name,
            'size' => $size,
            'size_label' => Addon::sizeLabel($size),
            'addon_price' => $addon->priceFor($size) ?? (float) $addon->addon_price,
            'is_available' => $addon->isAvailableIn($size),
        ];
    }

    /**
     * Why a line cannot be checked out for its add-ons, or null.
     *
     * @param  list<array<string, mixed>>  $lines  each with `food_name` and `unavailable_addons`
     */
    public static function blocker(Collection $lines): ?array
    {
        $affected = $lines->filter(fn (array $line) => ($line['unavailable_addons'] ?? []) !== []);

        if ($affected->isEmpty()) {
            return null;
        }

        $first = $affected->first();
        $names = collect($first['unavailable_addons'])->implode(', ');

        return [
            'code' => self::ERROR_CODE,
            'message' => $affected->count() === 1
                ? $names.', on your '.$first['food_name'].', is not available right now. Remove that item and add it again without it.'
                : 'Some add-ons in your order are not available right now. Remove those items and add them again without them.',
        ];
    }
}
