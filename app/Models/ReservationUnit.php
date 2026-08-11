<?php

namespace App\Models;

use App\Models\Concerns\GuardsInventoryWrites;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a reservation: a room type, a quantity and the dates it covers.
 * The quantity is the thing Inquiry cannot express today.
 *
 * @property int $id
 * @property int $reservation_id
 * @property int $room_type_id
 * @property int $quantity
 * @property CarbonImmutable $check_in
 * @property CarbonImmutable $check_out
 * @property float $total_amount
 * @property string $currency
 * @property-read Reservation|null $reservation
 * @property-read RoomType|null $roomType
 * @property int|null $rate_plan_id
 * @property-read Collection<int, ReservationNight> $nights
 * @property-read Collection<int, ReservationGuest> $guests
 */
class ReservationUnit extends Model
{
    use GuardsInventoryWrites;

    protected $fillable = [
        'reservation_id',
        'room_type_id',
        'rate_plan_id',
        'quantity',
        'check_in',
        'check_out',
        'total_amount',
        'currency',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'check_in' => 'date',
        'check_out' => 'date',
        'total_amount' => 'float',
    ];

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * @return HasMany<ReservationNight, $this>
     */
    public function nights(): HasMany
    {
        return $this->hasMany(ReservationNight::class);
    }

    /**
     * Who is in this room — the occupancy the price was computed from. Empty
     * for a property that prices per room and never asks.
     *
     * @return HasMany<ReservationGuest, $this>
     */
    public function guests(): HasMany
    {
        return $this->hasMany(ReservationGuest::class);
    }
}
