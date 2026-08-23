<?php

namespace App\Models;

use App\Enums\PlaceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * A tourism location — a park, a reserve, a landmark. Where a traveler goes,
 * and where a lodge stands when it stands nowhere near a town.
 *
 * Not a City: a city is an address, and Onguma has neither a street nor a
 * postcode. A listing can carry both, and they answer different questions —
 * see the create_places_table migration.
 *
 * @property int $id
 * @property int $region_id
 * @property int|null $destination_id
 * @property int|null $city_id
 * @property string $slug
 * @property PlaceType $type
 * @property string|null $image
 * @property float|null $lat
 * @property float|null $lng
 */
class Place extends Model
{
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['name'];

    protected $fillable = [
        'name',
        'slug',
        'type',
        'region_id',
        'destination_id',
        'city_id',
        'image',
        'lat',
        'lng',
    ];

    protected $casts = [
        'type' => PlaceType::class,
        'lat' => 'float',
        'lng' => 'float',
    ];

    protected static function booted(): void
    {
        static::saving(function (Place $place) {
            if (blank($place->slug) && filled($place->getTranslation('name', 'en', useFallbackLocale: true))) {
                $place->slug = Str::slug($place->getTranslation('name', 'en', useFallbackLocale: true));
            }
        });
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * The area a traveler names — "Etosha" over Etosha National Park, Onguma
     * and Ongava.
     *
     * @return BelongsTo<Destination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /**
     * The nearest town, where there is one. A fallback for an address and for
     * "what is near here" — a place is not *in* a city.
     *
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}
