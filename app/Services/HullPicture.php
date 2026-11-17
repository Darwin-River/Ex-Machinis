<?php

namespace App\Services;

use App\Resource;

/**
 * A drone's picture on its page (#802): its hull, rendered by tools/render.
 *
 * A craft hull (one of the 12 hulls a player can build, ids 6001 to 6231) is
 * drawn with the subsystems its recipe uses, and each one is numbered on the
 * picture: html/images/resources/<id>-parts.webp, and beside it .json, the
 * recipe and where each subsystem shows (render.py resource --parts). Any
 * other hull with a picture, such as the Mosquito every released drone starts
 * in, is drawn plain: html/images/hulls/<id>.webp and .json (render.py hull).
 */
class HullPicture
{
    /**
     * @return array{image: string, width: int, height: int, items: array}|null
     *         items: the recipe, numbered, each with where it shows ('at', as
     *         percentages; empty when it is out of sight). Null if the hull has
     *         no picture.
     */
    public static function of(?int $hullId): ?array
    {
        if (!$hullId) {
            return null;
        }
        foreach (["images/resources/$hullId-parts", "images/hulls/$hullId"] as $base) {
            if (is_file(public_path("$base.webp")) && is_file(public_path("$base.json"))) {
                return self::read($base);
            }
        }

        return null;
    }

    private static function read(string $base): ?array
    {
        $data = json_decode((string) file_get_contents(public_path("$base.json")), true);
        if (!is_array($data)) {
            return null;
        }
        $recipe = $data['recipe'] ?? [];
        $names = Resource::whereIn('id', array_column($recipe, 'id'))->pluck('name', 'id');
        $items = [];
        foreach (array_values($recipe) as $k => $r) {
            $items[] = [
                'n' => $k + 1,
                'id' => (int) $r['id'],
                'name' => $names[$r['id']] ?? "Resource {$r['id']}",
                'qty' => (int) $r['qty'],
                'at' => array_values(array_map(fn ($p) => [round($p['x'] * 100, 2), round($p['y'] * 100, 2)],
                    array_filter($data['parts'] ?? [], fn ($p) => (int) $p['id'] === (int) $r['id']))),
            ];
        }

        return ['image' => url("$base.webp"), 'width' => (int) $data['width'], 'height' => (int) $data['height'], 'items' => $items];
    }
}
