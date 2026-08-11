<?php

namespace App\Models;

use App\Enums\BoardBasis;
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
 * @property BoardBasis|null $board_basis
 * @property string|null $rate_plan_name
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
        'board_basis',
        'rate_plan_name',
        'quantity',
        'check_in',
        'check_out',
        'total_amount',
        'currency',
    ];

    protected $casts = [
        'board_basis' => BoardBasis::class,
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

    /**
     * What this room was sold as — "Standard rate (DBB)".
     *
     * Read from the frozen columns rather than from the plan, which is the
     * whole point of freezing them: this answers what the guest bought, and it
     * keeps answering after the plan has been renamed or removed.
     */
    public function soldAs(): ?string
    {
        $board = $this->board_basis?->shortLabel();

        if ($this->rate_plan_name === null) {
            return $board;
        }

        return $board === null ? $this->rate_plan_name : $this->rate_plan_name.' ('.$board.')';
    }
}
