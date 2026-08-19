<?php

namespace App\Models;

use App\Enums\PlaceType;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A place a listing can stand in — see PlaceType. Namibia's smaller
 * localities (Dörfer/Siedlungen) are modeled here rather than as a separate
 * entity, distinguished only by `type`, and since 2026-08-18 so are the
 * tourism areas that are not settlements at all: Etosha, Onguma, Sossusvlei.
 * A lodge in the middle of a park has no town, and inventing the nearest one
 * puts it ~100 km from where it is — the table name stays `cities` because
 * it is referenced everywhere, but the thing it holds is a place.
 *
 * @property int $id
 * @property int $region_id
 * @property string $name
 * @property string $slug
 * @property string|null $image
 * @property PlaceType $type
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
        'type' => PlaceType::class,
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
     * @return HasMany<Listing, $this>
     */
    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }
}
