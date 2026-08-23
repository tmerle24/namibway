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

    /**
     * The place a city is, creating it if the city has none yet.
     *
     * Which cities get a place was decided once, at migration time, from what
     * the catalog looked like then: the larger settlement types, anywhere
     * already hosting a published listing, and anywhere curated into a tourism
     * area. A catalog grows, and the rule has no way to notice — so the first
     * published listing in a village nobody had thought about would leave that
     * listing with no place at all, and a listing with no place is invisible
     * to the trip plan: no day location, no driving times, no map pin.
     *
     * So the answer is not a smarter rule but a later one: a place exists when
     * somewhere turns out to be a place, which is exactly the moment a
     * business opens there.
     *
     * Idempotent on the slug, and it links the city back, so calling it twice
     * for the same city returns the same row. Coordinates are inherited and may
     * be null — namibway:backfill-city-coordinates fills those in and copies
     * them across.
     */
    public static function forCity(City $city): self
    {
        if ($city->place_id !== null) {
            /** @var self $existing */
            $existing = $city->place()->firstOrFail();

            return $existing;
        }

        $place = self::firstOrCreate(
            ['slug' => $city->slug],
            [
                'name' => ['en' => $city->name],
                'type' => PlaceType::Town,
                'region_id' => $city->region_id,
                'destination_id' => $city->destination_id,
                'city_id' => $city->id,
                'image' => $city->image,
                'lat' => $city->lat,
                'lng' => $city->lng,
            ],
        );

        $city->forceFill(['place_id' => $place->id])->saveQuietly();

        return $place;
    }

    /**
     * What a traveller calls this place: "Etosha" for "Etosha National Park",
     * "Onguma" for "Onguma Nature Reserve".
     *
     * Null for a town, whose name is already the short one, and for anything
     * where stripping the descriptor leaves nothing.
     *
     * Two things read this and they must not drift: ItineraryService builds its
     * alias index from it, so the model's "Etosha" resolves to the right row;
     * and namibway:backfill-listing-places matches it against listing names,
     * because a lodge called "Onguma Bush Camp" is on Onguma however far its
     * postal address says it is from anywhere.
     */
    public function shortName(): ?string
    {
        if ($this->type === PlaceType::Town) {
            return null;
        }

        $name = $this->getTranslation('name', 'en', useFallbackLocale: true);

        $short = trim((string) preg_replace(
            '/\s+(?:(?:private\s+)?(?:national|nature|game|plateau)\s+)?(?:park|reserve|conservancy)$/i',
            '',
            $name,
        ));

        return $short === '' || mb_strtolower($short) === mb_strtolower($name) ? null : $short;
    }
}
