<?php

namespace App\Sites\Blocks;

/**
 * A few numbers in large type, counted up as they come into view, with an
 * optional line of place names running underneath.
 *
 * "3 tours · 14 days · 5 regions" says in one glance what a paragraph needs a
 * minute for. The numbers are the business's own facts - nothing here is
 * computed. Added 2026-09-17 with the enterprise edition, usable in both.
 *
 * Not a section (see isSection()): a strip between bands, no number, no anchor.
 */
class StatsBlock extends BlockDefinition
{
    public const MAX_ITEMS = 4;

    public const MAX_TICKER = 16;

    public function type(): string
    {
        return 'stats';
    }

    public function label(): string
    {
        return 'Numbers';
    }

    public function isSection(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'items' => ['array', 'max:'.self::MAX_ITEMS],
            // Whole numbers only: they are counted up from zero.
            'items.*.value' => ['required', 'integer', 'min:0', 'max:999999'],
            'items.*.unit' => ['nullable', 'string', 'max:12'],
            'items.*.label' => ['required', 'string', 'max:60'],
            'ticker' => ['array', 'max:'.self::MAX_TICKER],
            'ticker.*' => ['string', 'max:40'],
        ];
    }

    public function isFilled(array $data): bool
    {
        return $this->filled($data, 'items') || $this->filled($data, 'ticker');
    }

    /**
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return ['items' => [], 'ticker' => []];
    }
}
