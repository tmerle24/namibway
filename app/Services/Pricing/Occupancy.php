<?php

namespace App\Services\Pricing;

use App\Enums\GuestKind;
use App\Models\GuestCategory;

/**
 * Who is in one room: a count per guest category.
 *
 * Immutable, and deliberately dumb — it holds counts and nothing else. Every
 * question that needs to know what a category *means* (does a child count
 * toward how full the room is, what share does it pay) is answered with the
 * categories passed in, because those belong to the property and change
 * without this class knowing.
 *
 * An empty occupancy is a real state, not a broken one: a property pricing per
 * room never says who is in it, and asking would be a form field with no
 * purpose.
 */
final class Occupancy
{
    /**
     * @param  array<int, int>  $counts  guest category id => how many
     */
    private function __construct(public readonly array $counts) {}

    /**
     * @param  array<int|string, int|string|null>  $counts
     */
    public static function of(array $counts): self
    {
        $clean = [];

        foreach ($counts as $categoryId => $count) {
            $id = (int) $categoryId;
            $people = (int) $count;

            if ($id <= 0 || $people <= 0) {
                continue;
            }

            $clean[$id] = ($clean[$id] ?? 0) + $people;
        }

        ksort($clean);

        return new self($clean);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->counts === [];
    }

    public function countFor(int $categoryId): int
    {
        return $this->counts[$categoryId] ?? 0;
    }

    /** Everybody in the room, infants included. */
    public function headcount(): int
    {
        return array_sum($this->counts);
    }

    /**
     * How full the room is for pricing purposes — the answer a 1000 / 1300 /
     * 1500 tariff is looked up with. Categories that do not count (a cot,
     * normally) are left out.
     *
     * @param  array<int, GuestCategory>  $categories  keyed by id
     */
    public function occupancyCount(array $categories): int
    {
        $count = 0;

        foreach ($this->counts as $categoryId => $people) {
            // A category the property has since removed still counts: dropping
            // it would quietly make a stay cheaper than it was sold for.
            if (($categories[$categoryId] ?? null)?->counts_toward_occupancy ?? true) {
                $count += $people;
            }
        }

        return $count;
    }

    /**
     * @param  array<int, GuestCategory>  $categories  keyed by id
     */
    public function countOfKind(array $categories, GuestKind $kind): int
    {
        $count = 0;

        foreach ($this->counts as $categoryId => $people) {
            if (($categories[$categoryId] ?? null)?->kind === $kind) {
                $count += $people;
            }
        }

        return $count;
    }

    /**
     * The occupancy as rows a form or a table reads, in category order.
     *
     * @param  array<int, GuestCategory>  $categories  keyed by id
     * @return array<int, array{category: GuestCategory, count: int}>
     */
    public function rows(array $categories): array
    {
        $rows = [];

        foreach ($categories as $category) {
            $count = $this->countFor($category->id);

            if ($count > 0) {
                $rows[] = ['category' => $category, 'count' => $count];
            }
        }

        return $rows;
    }
}
