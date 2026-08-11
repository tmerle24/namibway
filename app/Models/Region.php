<?php

namespace App\Models;

use Database\Factories\RegionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One of Namibia's 14 official political regions — and, once the concept
 * expands past Namibia, the level that carries the country. A region belongs
 * to exactly one country and a listing inherits it through its city, which is
 * why `country_code` lives here rather than on every listing.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $country_code ISO 3166-1 alpha-2; keys into config('region.countries')
 * @property string|null $image
 */
class Region extends Model
{
    /** @use HasFactory<RegionFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'country_code',
        'image',
    ];

    protected static function booted(): void
    {
        static::saving(function (Region $region) {
            if (blank($region->slug) && filled($region->name)) {
                $region->slug = Str::slug($region->name);
            }
        });
    }

    /**
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    /**
     * @return HasMany<Destination, $this>
     */
    public function destinations(): HasMany
    {
        return $this->hasMany(Destination::class);
    }
}
