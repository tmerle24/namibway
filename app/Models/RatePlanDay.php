<?php

namespace App\Models;

use App\Models\Concerns\GuardsInventoryWrites;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night's rate and restrictions under one rate plan.
 *
 * Sparse, exactly like the inventory calendar beside it: a missing row, or a
 * null override, means "follow the room type's own rate". The rule has one
 * home — App\Services\Inventory\DTOs\CalendarSnapshot — so the grid and the
 * booking path cannot disagree about what a night costs.
 *
 * Guarded like the rest of the inventory tables. A rate is money, and the
 * reason the single write path exists is that money written from five places
 * is money nobody can account for.
 *
 * @property int $id
 * @property int $rate_plan_id
 * @property int $room_type_id
 * @property CarbonImmutable $date
 * @property float|null $rate
 * @property int|null $min_stay
 * @property bool $closed_to_arrival
 * @property bool $closed_to_departure
 * @property-read RatePlan|null $ratePlan
 * @property-read RoomType|null $roomType
 */
class RatePlanDay extends Model
{
    use GuardsInventoryWrites;

    protected $fillable = [
        'rate_plan_id',
        'room_type_id',
        'date',
        'rate',
        'min_stay',
        'closed_to_arrival',
        'closed_to_departure',
    ];

    protected $casts = [
        'date' => 'date',
        'rate' => 'float',
        'min_stay' => 'integer',
        'closed_to_arrival' => 'boolean',
        'closed_to_departure' => 'boolean',
    ];

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
