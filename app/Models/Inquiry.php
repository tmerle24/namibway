<?php

namespace App\Models;

use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $listing_id
 * @property int|null $trip_id
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
 * @property string|null $notes
 */
class Inquiry extends Model
{
    protected $fillable = [
        'listing_id',
        'trip_id',
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
}
