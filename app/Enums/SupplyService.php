<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a traveller can actually get at a supply point.
 *
 * Deliberately the only classification this table has. The obvious
 * alternative — a `type` column saying "filling station" or "supermarket"
 * beside a list of services — asks the person entering a row the same
 * question twice and then lets the two answers disagree: a filling station
 * with a Woermann shop attached is both, and a supermarket with a pump
 * outside is both the other way round. The traveller never asks what kind of
 * business it is; they ask whether they can fill up, and whether they can buy
 * food for three nights of self-catering.
 *
 * Only Fuel and Groceries carry a rule (see App\Services\Routing\SupplyStopFinder
 * — a stop is named when the road ahead has none of that service for a long
 * way). The rest are informational: they cost nothing to record while somebody
 * is already entering the row, and they are exactly the things a Namibian road
 * trip runs out of.
 */
enum SupplyService: string implements HasColor, HasLabel
{
    case Fuel = 'fuel';

    /**
     * Enough to stock up from, not a shelf of crisps beside the till. The
     * distinction is the whole point of the rule this drives: naming a farm
     * stall as "the last shop before three self-catering nights" is worse
     * than naming nothing.
     */
    case Groceries = 'groceries';

    case Atm = 'atm';

    /** Drinking water to fill jerrycans from — not every camp has any. */
    case Water = 'water';

    /** Refill for a camping gas bottle. */
    case Gas = 'gas';

    case TyreRepair = 'tyre_repair';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fuel => 'Fuel',
            self::Groceries => 'Groceries',
            self::Atm => 'ATM',
            self::Water => 'Drinking water',
            self::Gas => 'Camping gas',
            self::TyreRepair => 'Tyre repair',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Fuel => 'warning',
            self::Groceries => 'success',
            self::Atm => 'info',
            self::Water, self::Gas => 'primary',
            self::TyreRepair => 'gray',
        };
    }

    /** Whether running out of this is what makes a stop worth naming on a leg. */
    public function isProvisioning(): bool
    {
        return $this === self::Fuel || $this === self::Groceries;
    }

    /**
     * How far the road ahead may go without this before the last place that
     * had it is worth naming, in kilometres.
     *
     * Fuel is the shorter of the two because the consequence is worse and
     * because a Namibian pump is not a guarantee — the standing advice is to
     * fill up whenever the chance comes, so the number is a good deal below
     * what a tank actually does. Groceries is longer: missing a supermarket
     * costs a dull dinner, not a tow.
     *
     * These are **straight-line** kilometres, because that is what the finder
     * measures, and they are deliberately below the road distances they stand
     * for — about 200 km of driving for fuel and 250 for food. A road is
     * longer than the line it follows, and in the Namib a good deal longer:
     * the C14 from Sesriem to the coast runs some 350 km over a 240 km line.
     * Using the road figures as if they were line figures would silently drop
     * exactly the drives this exists for, and that crossing is the case the
     * fuel number was settled on — at 180 it said nothing about Solitaire,
     * which is the one place on that road everybody stops for fuel.
     */
    public function gapKm(): float
    {
        return match ($this) {
            self::Fuel => 160.0,
            self::Groceries => 225.0,
            default => INF,
        };
    }

    /**
     * The stored value of every case, for validation and column checks.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
