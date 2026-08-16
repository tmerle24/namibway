<?php

namespace App\Models;

use App\Enums\InquiryKind;
use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $listing_id
 * @property int|null $trip_id
 * @property int|null $user_id
 * @property InquiryKind $kind
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $travel_dates
 * @property Carbon|null $check_in
 * @property Carbon|null $check_out
 * @property string|null $arrival_time
 * @property int $adults
 * @property int $children
 * @property string|null $message
 * @property InquiryStatus $status
 * @property string|null $connector_reference
 * @property string|null $bookable_unit_code
 * @property float|null $total_amount
 * @property string|null $currency
 * @property Carbon|null $hold_expires_at
 * @property string|null $notes
 * @property-read Collection<int, InquiryItem> $items
 */
class Inquiry extends Model
{
    // `user_id` is the account that sent the request — server-resolved from the
    // session, never from request input (see Trip's note on the same field).
    protected $fillable = [
        'listing_id',
        'trip_id',
        'user_id',
        'kind',
        'name',
        'email',
        'phone',
        'travel_dates',
        'check_in',
        'check_out',
        'arrival_time',
        'adults',
        'children',
        'message',
        'status',
        'connector_reference',
        'bookable_unit_code',
        'total_amount',
        'currency',
        'hold_expires_at',
        'notes',
    ];

    protected $casts = [
        'kind' => InquiryKind::class,
        'status' => InquiryStatus::class,
        'check_in' => 'date',
        'check_out' => 'date',
        'adults' => 'integer',
        'children' => 'integer',
        'total_amount' => 'float',
        'trip_id' => 'integer',
        'hold_expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * The lines of an order. Empty for every other kind of request.
     *
     * @return HasMany<InquiryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InquiryItem::class);
    }

    /**
     * The requested time as a person writes it — "19:30", not "19:30:00".
     *
     * Postgres hands back a `time` column with its seconds, which no restaurant
     * asked for and which reads as machine output in an email.
     */
    public function arrivalTimeLabel(): ?string
    {
        return filled($this->arrival_time) ? substr($this->arrival_time, 0, 5) : null;
    }

    /**
     * @return BelongsTo<Trip, $this>
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The stay this request holds on the property's calendar, where it took
     * one — provisional while the partner decides, confirmed once they say
     * yes. Unique on the reservation side, so there is never more than one.
     *
     * @return HasOne<Reservation, $this>
     */
    public function reservation(): HasOne
    {
        return $this->hasOne(Reservation::class);
    }
}
