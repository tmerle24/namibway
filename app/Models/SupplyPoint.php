<?php

namespace App\Models;

use App\Enums\FuelType;
use App\Enums\SupplyService;
use App\Support\OpeningHours;
use Database\Factories\SupplyPointFactory;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * A place on the road where you can get something you will need later.
 *
 * Fuel, and food you can cook. Not a thing you go and look at (that is an
 * Attraction) and not something you book (that is a Listing) — nobody plans a
 * day around a filling station. What makes it worth a row is that the road
 * ahead may not have another one for two hundred kilometres, and the trip plan
 * is the only thing that knows that before the traveller finds out.
 *
 * The rule that turns rows here into a line in the plan lives in
 * App\Services\Routing\SupplyStopFinder: a supply point is named on the leg
 * where the traveller passes it, and only when it is the last one before a
 * long stretch without. So most of these rows are never shown — which is
 * correct, and is why the interesting question was never "where are the
 * filling stations".
 *
 * @property string $name
 * @property string $slug
 * @property string|null $opening_hours
 * @property string|null $opening_hours_source
 * @property Collection<int, SupplyService>|null $services
 * @property Collection<int, FuelType>|null $fuel_types
 * @property float|null $lat
 * @property float|null $lng
 * @property bool $is_published
 */
class SupplyPoint extends Model
{
    /** @use HasFactory<SupplyPointFactory> */
    use HasFactory;

    use HasTranslations;

    /**
     * The name is not here on purpose: "Engen Kamanjab" is a proper noun, and
     * the note is the only thing anybody would read in their own language.
     *
     * @var array<int, string>
     */
    public array $translatable = ['note'];

    protected $fillable = [
        'name',
        'slug',
        'services',
        'fuel_types',
        'opening_hours',
        'opening_hours_source',
        'city_id',
        'place_id',
        'lat',
        'lng',
        'note',
        'verified_at',
        'is_published',
    ];

    protected $casts = [
        'services' => AsEnumCollection::class.':'.SupplyService::class,
        // Null where nobody has recorded which pumps there are, which the cast
        // gives back as null rather than as an empty collection — hence
        // fuelTypeList() below, and nothing reading the property directly.
        'fuel_types' => AsEnumCollection::class.':'.FuelType::class,
        'lat' => 'float',
        'lng' => 'float',
        'verified_at' => 'datetime',
        'is_published' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (SupplyPoint $point) {
            if (blank($point->slug) && filled($point->name)) {
                $point->slug = Str::slug($point->name);
            }
        });
    }

    /**
     * The park or reserve it sits in, where there is no town — Okaukuejo sells
     * fuel and is not in one.
     *
     * @return BelongsTo<Place, $this>
     */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * The town it is in, which is where most of them are.
     *
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * What you can get here, never null.
     *
     * The column is NOT NULL and the cast gives back a collection — but a row
     * built in memory has neither, and a single null here would take out the
     * one line in the trip plan this table exists for. Same shape as
     * Listing::amenityList(): the list is what callers read, the column is
     * where it happens to live.
     *
     * @return Collection<int, SupplyService>
     */
    public function serviceList(): Collection
    {
        return collect($this->services ?? []);
    }

    /**
     * Which pumps, never null — and empty means "not recorded" rather than
     * "neither". See the migration.
     *
     * @return Collection<int, FuelType>
     */
    public function fuelTypeList(): Collection
    {
        return collect($this->fuel_types ?? []);
    }

    public function provides(SupplyService $service): bool
    {
        return $this->serviceList()->contains($service);
    }

    /**
     * When it is open, or null where nobody has recorded it — and null where
     * what was recorded is outside the subset we are willing to read. See
     * App\Support\OpeningHours: a half-understood opening time is worse than
     * none, because the traveller drives on it.
     */
    public function openingHours(): ?OpeningHours
    {
        return OpeningHours::parse($this->opening_hours);
    }

    /**
     * Whether it can be found on a route at all. Coordinates are the only
     * thing that puts it on one; without them the row is a note to ourselves.
     */
    public function isRoutable(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }
}
