<?php

namespace App\Models;

use App\Enums\InquiryStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $listing_id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string|null $travel_dates
 * @property string|null $message
 * @property InquiryStatus $status
 * @property string|null $connector_reference
 * @property string|null $notes
 */
class Inquiry extends Model
{
    protected $fillable = [
        'listing_id',
        'name',
        'email',
        'phone',
        'travel_dates',
        'message',
        'status',
        'connector_reference',
        'notes',
    ];

    protected $casts = [
        'status' => InquiryStatus::class,
    ];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
