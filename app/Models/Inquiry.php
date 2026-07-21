<?php

namespace App\Models;

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
    ];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
