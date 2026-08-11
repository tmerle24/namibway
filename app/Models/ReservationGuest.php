<?php

namespace App\Models;

use App\Models\Concerns\GuardsInventoryWrites;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How many of one guest category are in one room line — the occupancy the
 * price was computed from.
 *
 * @property int $id
 * @property int $reservation_unit_id
 * @property int $guest_category_id
 * @property int $count
 * @property-read ReservationUnit|null $unit
 * @property-read GuestCategory|null $category
 */
class ReservationGuest extends Model
{
    use GuardsInventoryWrites;

    protected $fillable = [
        'reservation_unit_id',
        'guest_category_id',
        'count',
    ];

    protected $casts = [
        'count' => 'integer',
    ];

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class, 'reservation_unit_id');
    }

    /**
     * @return BelongsTo<GuestCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(GuestCategory::class, 'guest_category_id');
    }
}
