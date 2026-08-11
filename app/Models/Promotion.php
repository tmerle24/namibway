<?php

namespace App\Models;

use App\Enums\DiscountType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * An offer a property runs — see the migration for the shape and why the two
 * date windows are not one window.
 *
 * The model answers two questions and nothing else: **does this apply**, and
 * **how much comes off**. Both are pure functions of what they are handed, so
 * a table of examples is a real test. Claiming a use is the writer's business,
 * because it is a race and races are settled in the database.
 *
 * @property int $id
 * @property int $listing_id
 * @property string $name
 * @property string|null $code
 * @property DiscountType $discount_type
 * @property float $discount_value
 * @property Carbon|null $stay_from
 * @property Carbon|null $stay_until
 * @property Carbon|null $booked_from
 * @property Carbon|null $booked_until
 * @property int|null $min_nights
 * @property int|null $max_uses
 * @property int $uses
 * @property bool $is_active
 * @property-read Collection<int, RatePlan> $ratePlans
 * @property-read Collection<int, RoomType> $roomTypes
 */
class Promotion extends Model
{
    protected $fillable = [
        'listing_id',
        'name',
        'code',
        'discount_type',
        'discount_value',
        'stay_from',
        'stay_until',
        'booked_from',
        'booked_until',
        'min_nights',
        'max_uses',
        'uses',
        'is_active',
    ];

    protected $casts = [
        'discount_type' => DiscountType::class,
        'discount_value' => 'float',
        'stay_from' => 'date',
        'stay_until' => 'date',
        'booked_from' => 'date',
        'booked_until' => 'date',
        'min_nights' => 'integer',
        'max_uses' => 'integer',
        'uses' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * Which products this applies to. **Empty means all of them** — a lodge
     * saying "20% off in November" means every rate, and making them tick
     * each one would be a list they forget to update.
     *
     * @return BelongsToMany<RatePlan, $this>
     */
    public function ratePlans(): BelongsToMany
    {
        return $this->belongsToMany(RatePlan::class);
    }

    /**
     * @return BelongsToMany<RoomType, $this>
     */
    public function roomTypes(): BelongsToMany
    {
        return $this->belongsToMany(RoomType::class);
    }

    /** A code has to be typed; a public offer applies by itself. */
    public function needsCode(): bool
    {
        return filled($this->code);
    }

    public function isExhausted(): bool
    {
        return $this->max_uses !== null && $this->uses >= $this->max_uses;
    }

    /**
     * Whether this offer covers the stay in front of it.
     *
     * `$ratePlanIds` and `$roomTypeIds` are what the booking actually holds. A
     * promotion scoped to a rate plan applies when *any* line is on it, which
     * is the reading a lodge intends by "20% off the DBB rate" — the
     * alternative, refusing the whole booking because one line is on another
     * rate, would surprise everybody.
     *
     * @param  array<int, int>  $ratePlanIds
     * @param  array<int, int>  $roomTypeIds
     */
    public function appliesTo(
        CarbonInterface $checkIn,
        CarbonInterface $checkOut,
        int $nights,
        array $ratePlanIds,
        array $roomTypeIds,
        ?CarbonInterface $bookedOn = null,
    ): bool {
        if (! $this->is_active || $this->isExhausted()) {
            return false;
        }

        $in = Carbon::parse($checkIn)->startOfDay();
        $out = Carbon::parse($checkOut)->startOfDay();
        $booked = Carbon::parse($bookedOn ?? now())->startOfDay();

        // The whole stay has to sit inside the window. A stay that straddles
        // the end of an offer is not half discounted — that would need the
        // promotion to reach into the per-night pricing, which is exactly what
        // it is not allowed to do.
        if ($this->stay_from !== null && $in->lt($this->stay_from)) {
            return false;
        }

        // check_out is a departure day, not a night, so the last night is the
        // day before it.
        if ($this->stay_until !== null && $out->copy()->subDay()->gt($this->stay_until)) {
            return false;
        }

        if ($this->booked_from !== null && $booked->lt($this->booked_from)) {
            return false;
        }

        if ($this->booked_until !== null && $booked->gt($this->booked_until)) {
            return false;
        }

        if ($this->min_nights !== null && $nights < $this->min_nights) {
            return false;
        }

        $plans = $this->ratePlans->pluck('id')->all();

        if ($plans !== [] && array_intersect($plans, $ratePlanIds) === []) {
            return false;
        }

        $rooms = $this->roomTypes->pluck('id')->all();

        return $rooms === [] || array_intersect($rooms, $roomTypeIds) !== [];
    }

    /**
     * What comes off, given the stay's own numbers.
     *
     * `$nightlyAmounts` is every night of the booking as it was priced —
     * needed only by free nights, which takes the cheapest ones. Cheapest
     * rather than last: it is the way round a lodge advertises, and the way
     * round that favours the guest, and choosing the other would be a silent
     * decision worth arguing about.
     *
     * Never more than the total. A fixed amount larger than a short stay makes
     * it free, not negative.
     *
     * @param  array<int, float>  $nightlyAmounts
     */
    public function discountOn(float $total, array $nightlyAmounts = []): float
    {
        $discount = match ($this->discount_type) {
            DiscountType::Percentage => $total * ($this->discount_value / 100),
            DiscountType::FixedAmount => $this->discount_value,
            DiscountType::FreeNights => $this->freeNightsDiscount($nightlyAmounts),
        };

        return round(max(0.0, min($discount, $total)), 2);
    }

    /**
     * `discount_value` is how many nights are free. "Stay 4, pay 3" is a
     * minimum stay of 4 and a value of 1 — two fields that already exist,
     * rather than a third that would have to agree with them.
     *
     * @param  array<int, float>  $nightlyAmounts
     */
    private function freeNightsDiscount(array $nightlyAmounts): float
    {
        $free = (int) $this->discount_value;

        if ($free < 1 || $nightlyAmounts === []) {
            return 0.0;
        }

        sort($nightlyAmounts);

        // Never every night: an offer cannot make the whole stay free by
        // arithmetic accident on a stay shorter than it expected.
        $free = min($free, max(0, count($nightlyAmounts) - 1));

        return array_sum(array_slice($nightlyAmounts, 0, $free));
    }

    /** "SUMMER26 — 15% off" for a screen. */
    public function label(): string
    {
        $amount = match ($this->discount_type) {
            DiscountType::Percentage => rtrim(rtrim(number_format($this->discount_value, 2, '.', ''), '0'), '.').'% off',
            DiscountType::FixedAmount => number_format($this->discount_value, 2).' off',
            DiscountType::FreeNights => ((int) $this->discount_value).' free '.((int) $this->discount_value === 1 ? 'night' : 'nights'),
        };

        return $this->needsCode() ? $this->code.' — '.$amount : $this->name.' — '.$amount;
    }
}
