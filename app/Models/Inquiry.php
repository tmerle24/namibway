<?php

namespace App\Models;

use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $listing_id
 * @property int|null $trip_id
 * @property int|null $user_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $travel_dates
 * @property Carbon|null $check_in
 * @property Carbon|null $check_out
 * @property int $adults
 * @property int $children
 * @property string|null $message
 * @property InquiryStatus $status
 * @property string|null $connector_reference
 * @property string|null $room_type_code
 * @property float|null $total_amount
 * @property string|null $currency
 * @property Carbon|null $hold_expires_at
 * @property string|null $notes
 */
class Inquiry extends Model
{
    // `user_id` is the account that sent the request — server-resolved from the
    // session, never from request input (see Trip's note on the same field).
    protected $fillable = [
        'listing_id',
        'trip_id',
        'user_id',
        'name',
        'email',
        'phone',
        'travel_dates',
        'check_in',
        'check_out',
        'adults',
        'children',
        'message',
        'status',
        'connector_reference',
        'room_type_code',
        'total_amount',
        'currency',
        'hold_expires_at',
        'notes',
    ];

    protected $casts = [
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
