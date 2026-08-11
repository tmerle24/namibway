<?php

namespace App\Models;

use App\Models\Concerns\GuardsInventoryWrites;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one night of one unit line cost. A price record, never an availability
 * record — availability is the counter on room_type_calendar_days.
 *
 * @property int $id
 * @property int $reservation_unit_id
 * @property CarbonImmutable $date
 * @property int $units
 * @property float $rate
 * @property string $currency
 * @property-read ReservationUnit|null $reservationUnit
 */
class ReservationNight extends Model
{
    use GuardsInventoryWrites;

    protected $fillable = [
        'reservation_unit_id',
        'date',
        'units',
        'rate',
        'currency',
    ];

    protected $casts = [
        'date' => 'date',
        'units' => 'integer',
        'rate' => 'float',
    ];

    /**
     * @return BelongsTo<ReservationUnit, $this>
     */
    public function reservationUnit(): BelongsTo
    {
        return $this->belongsTo(ReservationUnit::class);
    }
}
