<?php

namespace App\Filament\Navigation;

use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Navigation\NavigationManager;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/**
 * Every item inside a navigation group is ordered by its label, in both panels.
 *
 * Filament orders on `$navigationSort` and falls back to registration order, so
 * an alphabetical sidebar had to be maintained by hand — a set of magic numbers
 * spread over a dozen classes that the next added page silently broke. Sorting
 * here means a new resource lands in the right place because of what it is
 * called, and nothing has to declare a sort to say so.
 *
 * Two deliberate exclusions:
 *
 * - The ungrouped top-level items are left as they are. In the partner panel
 *   that list is the working day in order (Calendar, Arrivals, Rates), which is
 *   a sequence, not a lookup — those still use `$navigationSort`.
 * - A panel with its own navigation builder spells its order out explicitly and
 *   is not second-guessed.
 */
class AlphabeticalNavigationManager extends NavigationManager
{
    /**
     * @return array<NavigationGroup>
     */
    public function get(): array
    {
        $groups = parent::get();

        if ($this->panel->hasNavigationBuilder()) {
            return $groups;
        }

        return array_map(
            fn (NavigationGroup $group): NavigationGroup => blank($group->getLabel())
                ? $group
                : $group->items($this->sortByLabel($group->getItems())),
            $groups,
        );
    }

    /**
     * @param  array<NavigationItem>|Arrayable<array-key, NavigationItem>  $items
     * @return Collection<array-key, NavigationItem>
     */
    protected function sortByLabel(array|Arrayable $items): Collection
    {
        return Collection::make($items)
            ->map(fn (NavigationItem $item): NavigationItem => filled($item->getChildItems())
                ? $item->childItems($this->sortByLabel($item->getChildItems()))
                : $item)
            ->sortBy(
                fn (NavigationItem $item): string => (string) $item->getLabel(),
                SORT_NATURAL | SORT_FLAG_CASE,
            );
    }
}
