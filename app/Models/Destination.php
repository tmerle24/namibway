<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $blurb
 * @property string|null $image
 * @property int $region_id
 * @property float|null $lat
 * @property float|null $lng
 * @property bool $is_published
 * @property int $sort_order
 */
class Destination extends Model
{
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['name', 'blurb'];

    protected $fillable = [
        'name',
        'slug',
        'blurb',
        'image',
        'region_id',
        'lat',
        'lng',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
        'lat' => 'float',
        'lng' => 'float',
    ];

    protected static function booted(): void
    {
        static::saving(function (Destination $destination) {
            if (blank($destination->slug) && filled($destination->name)) {
                $destination->slug = Str::slug($destination->name);
            }
        });
    }

    /**
     * The places that make up this area — Etosha holds the park itself,
     * Onguma and Ongava. What the destination card filters on, and what a
     * listing inherits its displayed area from through its place.
     *
     * @return HasMany<City, $this>
     */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * The tourism locations grouped under this area — Etosha National Park,
     * Onguma and Ongava under "Etosha". This is the one a traveler's click on
     * a destination card resolves to; `cities()` is the same grouping seen
     * from the address side.
     *
     * @return HasMany<Place, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(Place::class);
    }
}
