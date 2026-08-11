<?php

namespace App\Models;

use App\Models\Concerns\GuardsInventoryWrites;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night of one room type in the ARI calendar. Rows are sparse: a missing
 * row means "the room type's defaults apply", and `units_total` / `rate` are
 * overrides where null means the same thing.
 *
 * The counters are the availability answer, not a cache of one. They move
 * only through App\Services\Inventory\InventoryWriter, and only by
 * conditional UPDATE — see the migration for why.
 *
 * @property int $id
 * @property int $room_type_id
 * @property CarbonImmutable $date
 * @property int|null $units_total
 * @property int $units_sold
 * @property int $units_blocked
 * @property float|null $rate
 * @property int|null $min_stay
 * @property bool $closed_to_arrival
 * @property bool $closed_to_departure
 * @property-read RoomType|null $roomType
 */
class RoomTypeCalendarDay extends Model
{
    use GuardsInventoryWrites;

    protected $fillable = [
        'room_type_id',
        'date',
        'units_total',
        'units_sold',
        'units_blocked',
        'rate',
        'min_stay',
        'closed_to_arrival',
        'closed_to_departure',
    ];

    protected $casts = [
        'date' => 'date',
        'units_total' => 'integer',
        'units_sold' => 'integer',
        'units_blocked' => 'integer',
        'rate' => 'float',
        'min_stay' => 'integer',
        'closed_to_arrival' => 'boolean',
        'closed_to_departure' => 'boolean',
    ];

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
