<?php

namespace App\Models;

use App\Enums\SettlementType;
use Database\Factories\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A city, town, village, or settlement — see SettlementType. Namibia's
 * smaller localities (Dörfer/Siedlungen) are modeled here too rather than as
 * a separate entity, distinguished only by `type`.
 *
 * @property int $id
 * @property int $region_id
 * @property string $name
 * @property string $slug
 * @property SettlementType $type
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
        'type',
        'population',
        'area_km2',
        'lat',
        'lng',
    ];

    protected $casts = [
        'type' => SettlementType::class,
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
