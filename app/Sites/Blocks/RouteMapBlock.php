<?php

namespace App\Sites\Blocks;

/**
 * A drawn map of the journey: the country's outline in ink on parchment, and
 * the route across it in red, drawing itself stop by stop as it comes into
 * view.
 *
 * The timeline (ItineraryBlock) says what happens each day; this says where,
 * in one look - which is what a traveller sizing up a fourteen-day loop wants
 * before reading any of it. Stops carry their own coordinates, so the block
 * needs nothing from the platform. Outlines per country: config/map_outlines.php.
 * Added 2026-09-18 with the enterprise edition, usable in both.
 */
class RouteMapBlock extends BlockDefinition
{
    public const MAX_ITEMS = 16;

    public function type(): string
    {
        return 'route_map';
    }

    public function label(): string
    {
        return 'Route map';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'heading' => ['nullable', 'string', 'max:120'],
            'intro' => ['nullable', 'string', 'max:400'],
            // ISO 3166-1 alpha-2, an outline in config/map_outlines.php.
            'country' => ['nullable', 'string', 'size:2'],
            // The small title in the map's corner, e.g. "The Grand Namibia Safari".
            'title' => ['nullable', 'string', 'max:60'],
            'items' => ['array', 'max:'.self::MAX_ITEMS],
            'items.*.name' => ['required', 'string', 'max:40'],
            'items.*.label' => ['nullable', 'string', 'max:24'],
            'items.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'items.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'note' => ['nullable', 'string', 'max:200'],
        ], $this->navRules());
    }

    public function isFilled(array $data): bool
    {
        return count($data['items'] ?? []) >= 2;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['heading' => null, 'intro' => null, 'country' => 'NA', 'title' => null, 'items' => [], 'note' => null];
    }
}
