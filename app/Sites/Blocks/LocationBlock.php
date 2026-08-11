<?php

namespace App\Sites\Blocks;

/**
 * Where the business is, and a link that opens the way there.
 *
 * The flyer's words are "a map that opens straight into Google Maps", and that
 * is exactly what this renders — an address, whatever landmark note the owner
 * dictates, and a link. No embedded map.
 *
 * That is not a shortcut. An interactive map is a JavaScript library plus tile
 * requests to a third party, on a page whose whole promise is that it loads on
 * an old phone over a weak network — and the visitor's next action is to open
 * their own maps app anyway, which the link does in one tap and does better,
 * because it starts navigation from where they are standing.
 */
class LocationBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'location';
    }

    public function label(): string
    {
        return 'Location';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'heading' => ['nullable', 'string', 'max:120'],
            'directions_note' => ['nullable', 'string', 'max:600'],
        ];
    }

    public function isFilled(array $data): bool
    {
        // The address lives on the site, not in this payload, so the renderer
        // decides — a location block with neither an address nor coordinates
        // has nothing to point at and is switched off by generation.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['heading' => 'Find us', 'directions_note' => null];
    }
}
