<?php

namespace App\Models;

use App\Models\Concerns\GuardsInventoryWrites;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One night of one room type in the ARI calendar. Rows are sparse: a missing
 * row means "the room type's defaults apply", and `units_total` is an
 * override where null means the same thing.
 *
 * **Inventory only.** Rate and restrictions moved to RatePlanDay, because a
 * room is sold once however many rate plans it is offered under — see
 * BOOKING_SYSTEM.md. Everything left here is keyed by room type alone, which
 * is what keeps the conditional UPDATE below correct no matter how many
 * products the property sells the room under.
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
    ];

    protected $casts = [
        'date' => 'date',
        'units_total' => 'integer',
        'units_sold' => 'integer',
        'units_blocked' => 'integer',
    ];

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }
}
