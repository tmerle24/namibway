<?php

namespace App\Models;

use App\Enums\ReservationSource;
use App\Enums\StayStatus;
use App\Models\Concerns\GuardsInventoryWrites;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A stay at a property. Not an Inquiry — see CLAUDE.md, "How a confirmed
 * Inquiry becomes a Reservation", for why the two stay separate and how the
 * one-way promotion is meant to work. `inquiry_id` is that bridge and nothing
 * writes it yet.
 *
 * @property int $id
 * @property string $reference
 * @property int $listing_id
 * @property int|null $inquiry_id
 * @property StayStatus $status
 * @property ReservationSource $source
 * @property string $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property CarbonImmutable $check_in
 * @property CarbonImmutable $check_out
 * @property int $adults
 * @property int $children
 * @property float|null $total_amount
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
        'inquiry_id',
        'status',
        'source',
        'guest_name',
        'guest_email',
        'guest_phone',
        'check_in',
        'check_out',
        'adults',
        'children',
        'total_amount',
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
        'cancelled_at' => 'datetime',
    ];

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

    /** Nights, not days — a stay from the 5th to the 8th is three nights. */
    public function nights(): int
    {
        return (int) $this->check_in->diffInDays($this->check_out);
    }
}
