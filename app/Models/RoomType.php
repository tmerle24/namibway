<?php

namespace App\Models;

use App\Enums\AmenityCategory;
use Database\Factories\RoomTypeFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 * @property-read Collection<int, Amenity> $amenities
 */
class RoomType extends Model
{
    /** @use HasFactory<RoomTypeFactory> */
    use HasFactory;

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

    /**
     * What this room has, from the shared catalogue.
     *
     * A plain pivot with no columns of its own: a per-room note ("hot water
     * from 6pm") was considered and left out, because it would turn a
     * multi-select somebody fills in once into a repeater they fill in forty
     * times. The room's own description is where a sentence belongs.
     *
     * @return BelongsToMany<Amenity, $this>
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class)->withTimestamps();
    }

    /**
     * The room's amenities grouped for reading — bathroom together, power
     * together. Empty categories are left out rather than shown blank.
     *
     * @return array<string, array<int, string>> category label => names
     */
    public function amenitiesByCategory(): array
    {
        $grouped = [];

        foreach (AmenityCategory::inReadingOrder() as $category) {
            $names = $this->amenities
                ->where('category', $category)
                ->sortBy('sort')
                ->pluck('name')
                ->all();

            if ($names !== []) {
                $grouped[$category->label()] = array_values($names);
            }
        }

        return $grouped;
    }

    /**
     * The ARI calendar for this room type. Sparse — a night with no row uses
     * the defaults on this model, so absence of rows is normal and never a
     * gap to be filled in. Read it through
     * App\Services\Inventory\AvailabilityCalendar rather than by hand.
     *
     * @return HasMany<RoomTypeCalendarDay, $this>
     */
    public function calendarDays(): HasMany
    {
        return $this->hasMany(RoomTypeCalendarDay::class);
    }
}
