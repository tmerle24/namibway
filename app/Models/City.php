<?php

namespace App\Models;

use App\Enums\CityType;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A city, town, village or settlement — see CityType. This is an *address*:
 * where a listing's street address resolves to, and Namibia's administrative
 * gazetteer with its smaller localities modelled here too, distinguished only
 * by `type`.
 *
 * Between 2026-08-18 and 2026-08-23 it also held parks, reserves and landmarks,
 * so that a lodge standing in one had somewhere to be filed. Those are a
 * different noun and now live in `places` — a city is where you get post, a
 * place is where you go. A listing carries both, and for a camp on Onguma they
 * are not the same answer.
 *
 * @property int $id
 * @property int $region_id
 * @property int|null $destination_id
 * @property int|null $place_id
 * @property string $name
 * @property string $slug
 * @property string|null $image
 * @property CityType $type
 * @property int|null $population
 * @property float|null $area_km2
 * @property float|null $lat
 * @property float|null $lng
 */
class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    protected $fillable = [
        'region_id',
        'destination_id',
        'place_id',
        'name',
        'slug',
        'image',
        'type',
        'population',
        'area_km2',
        'lat',
        'lng',
    ];

    protected $casts = [
        'type' => CityType::class,
        'population' => 'integer',
        'area_km2' => 'float',
        'lat' => 'float',
        'lng' => 'float',
    ];

    protected static function booted(): void
    {
        static::saving(function (City $city) {
            if (blank($city->slug) && filled($city->name)) {
                $city->slug = Str::slug($city->name);
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
     * The tourism area this place sits in — Onguma is in Etosha, Sesriem is in
     * Sossusvlei. Nullable, because plenty of places are in none: Windhoek is
     * simply Windhoek. This is what a traveler is shown; `region` is the
     * political one and stays internal (see the 2026-08-19 migration).
     *
     * @return BelongsTo<Destination, $this>
     */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /**
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    /**
     * The tourism location this city belongs to, where it is one a traveler
     * plans around. Null for the villages nobody stays in.
     *
     * Everything Kaia routes on resolves to a place, which is what keeps the
     * driving matrix place-to-place instead of a polymorphic city-or-place
     * pair — see the create_places_table migration.
     *
     * @return BelongsTo<Place, $this>
     */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }
}
