<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bookable room/unit type for listings on the Native booking connector
 * (see App\Connectors\Native\NativeConnector). Availability is derived, not
 * stored: total_units minus overlapping active Inquiry rows for the same
 * listing_id + room_type_code (Inquiry::room_type_code matches `code` here).
 *
 * @property int $id
 * @property int $listing_id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property array<int, string>|null $gallery
 * @property int $max_adults
 * @property int $max_children
 * @property int $total_units
 * @property float $rate_per_night
 * @property string $currency
 * @property bool $is_active
 */
class RoomType extends Model
{
    protected $fillable = [
        'listing_id',
        'code',
        'name',
        'description',
        'gallery',
        'max_adults',
        'max_children',
        'total_units',
        'rate_per_night',
        'currency',
        'is_active',
    ];

    protected $casts = [
        'gallery' => 'array',
        'max_adults' => 'integer',
        'max_children' => 'integer',
        'total_units' => 'integer',
        'rate_per_night' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
