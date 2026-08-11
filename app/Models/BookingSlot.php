<?php

namespace App\Models;

use App\Exceptions\Inventory\DepartureInUseException;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * One departure a bookable unit runs — see the migration for why it is a row
 * with a start and a length rather than an index.
 *
 * The timetable, not a booking: this is what the operator publishes and reuses
 * every day, and a stay on a particular date points at it. Nothing here counts
 * anything; the counter stays on the calendar day, one per (unit, date, slot).
 *
 * @property int $id
 * @property int $bookable_unit_id
 * @property string|null $label
 * @property string $starts_at
 * @property int $duration_minutes
 * @property bool $is_active
 * @property int $sort
 */
class BookingSlot extends Model
{
    protected $fillable = [
        'bookable_unit_id',
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
     * Deleting a departure is refused once anything has been sold on it.
     *
     * Both `bookable_unit_calendar_days.slot_id` and `rate_plan_days.slot_id`
     * cascade, so a delete reaches the counters through the database — below
     * Eloquent, past InventoryWriteGuard, and with nothing left to restore
     * from. This is the one place that can stop it, so it lives on the model
     * rather than on a screen: a timetable is editable from more than one
     * panel, and a rule only one of them knows is not a rule.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $slot): void {
            $seats = $slot->seatsHeld();

            if ($seats > 0) {
                throw new DepartureInUseException($slot->label(), $seats);
            }
        });
    }

    /** Seats sold or held off sale on this departure, on any date. */
    public function seatsHeld(): int
    {
        return (int) BookableUnitCalendarDay::query()
            ->where('slot_id', $this->id)
            ->sum(DB::raw('units_sold + units_blocked'));
    }

    /**
     * @return BelongsTo<BookableUnit, $this>
     */
    public function bookableUnit(): BelongsTo
    {
        return $this->belongsTo(BookableUnit::class);
    }

    /**
     * The timetable a unit runs, in the order it reads on a day view.
     *
     * @return Collection<int, self>
     */
    public static function forUnit(BookableUnit $bookableUnit): Collection
    {
        return self::query()
            ->where('bookable_unit_id', $bookableUnit->id)
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
