<?php

namespace App\Models;

use App\Models\Concerns\GuardsInventoryWrites;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night's amount for one number of guests — OTA's `BaseByGuestAmt`. See
 * the migration for why it carries a date.
 *
 * Guarded like the rest of the inventory tables: this is money, and money
 * written from five places is money nobody can account for.
 *
 * @property int $id
 * @property int $rate_plan_id
 * @property int $bookable_unit_id
 * @property CarbonImmutable $date
 * @property int $guests
 * @property float $amount
 * @property-read RatePlan|null $ratePlan
 * @property-read BookableUnit|null $bookableUnit
 */
class RatePlanGuestAmount extends Model
{
    use GuardsInventoryWrites;

    protected $fillable = [
        'rate_plan_id',
        'bookable_unit_id',
        'date',
        'guests',
        'amount',
    ];

    protected $casts = [
        'date' => 'date',
        'guests' => 'integer',
        'amount' => 'float',
    ];

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * @return BelongsTo<BookableUnit, $this>
     */
    public function bookableUnit(): BelongsTo
    {
        return $this->belongsTo(BookableUnit::class);
    }
}
