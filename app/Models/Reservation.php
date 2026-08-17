<?php

namespace App\Models;

use App\Enums\FolioStatus;
use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Models\Concerns\GuardsInventoryWrites;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A stay at a property. Not an Inquiry — see CLAUDE.md, "How a confirmed
 * Inquiry becomes a Reservation", for why the two stay separate and how the
 * one-way promotion is meant to work. `inquiry_id` is that bridge and nothing
 * writes it yet.
 *
 * @property int $id
 * @property string $reference
 * @property int $listing_id
 * @property int|null $customer_id
 * @property int|null $inquiry_id
 * @property StayStatus $status
 * @property ReservationSource $source
 * @property string $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property CarbonImmutable $check_in
 * @property string|null $pickup_time  HH:MM — vehicle hand-over time, null for non-vehicle stays.
 * @property CarbonImmutable $check_out
 * @property string|null $return_time  HH:MM — vehicle return time, null for non-vehicle stays.
 * @property int $adults
 * @property int $children
 * @property float|null $total_amount
 * @property float|null $quoted_amount
 * @property int|null $promotion_id
 * @property string|null $promotion_code
 * @property float|null $discount_amount
 * @property float|null $commission_rate
 * @property float|null $commission_base
 * @property float|null $commission_amount
 * @property CarbonImmutable|null $commission_earned_at
 * @property float|null $deposit_rate
 * @property float|null $deposit_amount
 * @property float|null $charges_amount
 * @property float $paid_amount
 * @property FolioStatus $payment_status
 * @property string|null $price_override_reason
 * @property string|null $over_capacity_note
 * @property int|null $price_overridden_by
 * @property CarbonImmutable|null $price_overridden_at
 * @property string $currency
 * @property string|null $notes
 * @property int|null $created_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property-read Listing|null $listing
 * @property-read Inquiry|null $inquiry
 * @property-read Collection<int, ReservationUnit> $units
 */
class Reservation extends Model
{
    use GuardsInventoryWrites;

    protected $fillable = [
        'reference',
        'listing_id',
        'customer_id',
        'inquiry_id',
        'status',
        'source',
        'guest_name',
        'guest_email',
        'guest_phone',
        'check_in',
        'pickup_time',
        'check_out',
        'return_time',
        'adults',
        'children',
        'total_amount',
        'quoted_amount',
        'promotion_id',
        'promotion_code',
        'discount_amount',
        'charges_amount',
        'paid_amount',
        'payment_status',
        'commission_rate',
        'commission_base',
        'commission_amount',
        'commission_earned_at',
        'deposit_rate',
        'deposit_amount',
        'price_override_reason',
        'price_overridden_by',
        'price_overridden_at',
        'over_capacity_note',
        'currency',
        'notes',
        'created_by',
        'cancelled_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'status' => StayStatus::class,
        'source' => ReservationSource::class,
        'check_in' => 'date',
        'check_out' => 'date',
        'adults' => 'integer',
        'children' => 'integer',
        'total_amount' => 'float',
        'quoted_amount' => 'float',
        'discount_amount' => 'float',
        'charges_amount' => 'float',
        'paid_amount' => 'float',
        'payment_status' => FolioStatus::class,
        'commission_rate' => 'float',
        'commission_base' => 'float',
        'commission_amount' => 'float',
        'commission_earned_at' => 'datetime',
        'deposit_rate' => 'float',
        'deposit_amount' => 'float',
        'price_overridden_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * The offer that came off this stay, where one did. Nulled if the offer is
     * ever deleted — the amount and the code stay, because they are what the
     * guest was charged.
     *
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * @return BelongsTo<Inquiry, $this>
     */
    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    /**
     * Whose stay this is. The frozen guest_* strings beside it are what this
     * booking said at the time and do not follow a later correction.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * What staff have written about this stay since it was taken. The `notes`
     * column is a different thing — see the notes migration.
     *
     * @return MorphMany<Note, $this>
     */
    public function noteLog(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable')->latest();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<ReservationUnit, $this>
     */
    public function units(): HasMany
    {
        return $this->hasMany(ReservationUnit::class);
    }

    /**
     * The taxes, levies and fees as they were at booking. Frozen — never
     * recomputed, so a VAT change cannot rewrite an old invoice.
     *
     * @return HasMany<ReservationCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(ReservationCharge::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * What the stay itself came to, before anything was added on top. Derived
     * rather than stored: it is the total minus the added charges, and a
     * fourth money column would be a fourth thing that can disagree.
     */
    public function stayAmount(): float
    {
        return round(($this->total_amount ?? 0.0) - ($this->charges_amount ?? 0.0), 2);
    }

    /**
     * The money that has moved against this stay, oldest first — the credit
     * side of the folio. `paid_amount` beside it is the same thing summed and
     * written down, which is what every screen reads instead of this.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('received_at')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function priceOverrider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'price_overridden_by');
    }

    /**
     * Whether somebody charged something other than what the calendar priced.
     *
     * Derived from the amounts rather than stored as a flag, so it stays true
     * to the numbers even if one of them is later corrected. Compared in whole
     * cents, because these are money columns and a float equality check on
     * money is a bug waiting for a rounding difference.
     *
     * The comparison is between the **stay** and what the calendar quoted less
     * any discount — which is exactly the pair InventoryWriter compares when it
     * decides to record an override. It used to compare `total_amount` against
     * `quoted_amount`, and those are not the same kind of number: the total
     * carries the added taxes and fees and the quote does not. Any property
     * with VAT added on top therefore reported *every* booking as
     * price-overridden, and the stay drawer printed "calendar said N$ 7,350"
     * on a booking nobody had touched. Found by looking at the screen.
     */
    public function priceWasOverridden(): bool
    {
        if ($this->quoted_amount === null || $this->total_amount === null) {
            return false;
        }

        $expected = Money::cents($this->quoted_amount) - Money::cents($this->discount_amount ?? 0.0);

        return Money::cents($this->stayAmount()) !== $expected;
    }

    /** Nights, not days — a stay from the 5th to the 8th is three nights. */
    public function nights(): int
    {
        return (int) $this->check_in->diffInDays($this->check_out);
    }
}
