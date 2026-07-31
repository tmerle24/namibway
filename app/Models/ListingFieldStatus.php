<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $listing_id
 * @property string $field
 * @property string $status
 */
class ListingFieldStatus extends Model
{
    protected $table = 'listing_field_status';

    protected $fillable = [
        'listing_id',
        'field',
        'status',
    ];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
