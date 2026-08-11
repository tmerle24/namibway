<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One departure a bookable unit runs — see the migration for why it is a row
 * with a start and a length rather than an index.
 *
 * The timetable, not a booking: this is what the operator publishes and reuses
 * every day, and a stay on a particular date points at it. Nothing here counts
 * anything; the counter stays on the calendar day, one per (unit, date, slot).
 *
 * @property int $id
 * @property int $room_type_id
 * @property string|null $label
 * @property string $starts_at
 * @property int $duration_minutes
 * @property bool $is_active
 * @property int $sort
 */
class BookingSlot extends Model
{
    protected $fillable = [
        'room_type_id',
        'label',
        'starts_at',
        'duration_minutes',
        'is_active',
        'sort',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'is_active' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class);
    }

    /**
     * The timetable a unit runs, in the order it reads on a day view.
     *
     * @return Collection<int, self>
     */
    public static function forUnit(RoomType $roomType): Collection
    {
        return self::query()
            ->where('room_type_id', $roomType->id)
            ->where('is_active', true)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
    }

    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** "09:00" — what the column is labelled with when there is no name. */
    public function timeLabel(): string
    {
        return substr($this->starts_at, 0, 5);
    }

    /** "Morning departure" where there is one, the time where there is not. */
    public function label(): string
    {
        return filled($this->label) ? (string) $this->label : $this->timeLabel();
    }

    /**
     * When this departure ends, on a given day.
     *
     * Returned rather than stored, and it may land on the next day: a sunset
     * drive that runs past midnight is a duration, not a second date. The
     * inventory it consumes belongs to the day it departs.
     */
    public function endsOn(CarbonInterface $date): CarbonInterface
    {
        return $date->copy()->startOfDay()
            ->setTimeFromTimeString($this->starts_at)
            ->addMinutes($this->duration_minutes);
    }
}
