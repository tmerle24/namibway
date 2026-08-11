<?php

namespace App\Models;

use App\Enums\ChargeBase;
use App\Enums\ChargeBasis;
use App\Enums\ChargeKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tax, levy or fee a property adds to a price. See the migration for why it
 * is not part of a rate plan.
 *
 * Nothing here computes anything — App\Services\Pricing\ChargeCalculator does,
 * and it is the only thing that should. A model that could price itself is a
 * model somebody will price a stay with, from a screen, months from now.
 *
 * @property int $id
 * @property int $listing_id
 * @property string $name
 * @property ChargeKind $kind
 * @property ChargeBasis $basis
 * @property ChargeBase $base
 * @property float $rate
 * @property bool $is_included
 * @property bool $charges_children
 * @property CarbonInterface|null $valid_from
 * @property CarbonInterface|null $valid_until
 * @property bool $is_active
 * @property int $sort
 */
class Charge extends Model
{
    protected $fillable = [
        'listing_id',
        'name',
        'kind',
        'basis',
        'base',
        'rate',
        'is_included',
        'charges_children',
        'valid_from',
        'valid_until',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'kind' => ChargeKind::class,
        'basis' => ChargeBasis::class,
        'base' => ChargeBase::class,
        'rate' => 'float',
        'is_included' => 'boolean',
        'charges_children' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * The charges a property applies to a stay arriving on a given day, in the
     * order they belong on an invoice.
     *
     * Keyed on the **arrival** date rather than on each night: a rate that
     * changes mid-stay is a question nobody in this market asks, and answering
     * it would mean a charge per night on every invoice.
     *
     * @return Collection<int, self>
     */
    public static function forStay(Listing $listing, CarbonInterface $checkIn): Collection
    {
        return self::query()
            ->where('listing_id', $listing->id)
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('valid_from')
                ->orWhereDate('valid_from', '<=', $checkIn))
            ->where(fn (Builder $query) => $query
                ->whereNull('valid_until')
                ->orWhereDate('valid_until', '>=', $checkIn))
            ->orderBy('sort')
            ->orderBy('id')
            ->get();
    }
}
