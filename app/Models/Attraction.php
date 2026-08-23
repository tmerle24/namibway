<?php

namespace App\Models;

use App\Enums\AttractionType;
use App\Enums\ContentSource;
use Database\Factories\AttractionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * A thing you go and look at, as opposed to a place you are in or a business
 * you book.
 *
 * The Hoba Meteorite, Deadvlei, the dinosaur footprints at Otjihaenamaparero,
 * a rock-art site with no lodge on it. Nothing is sold there, so it is not a
 * Listing; nothing is filed under it, so it is not a Place. It sits *in* one —
 * `place_id` for the tourism location, `city_id` for the nearest town, both
 * optional, with its own coordinates as the thing that actually locates it.
 *
 * The columns that earn this table its keep are the operational ones:
 * visit_minutes, entry_fee, requires_4x4, requires_permit, access_note,
 * best_time_note. What a language model already knows about the Hoba meteorite
 * is free and differentiates nothing; which track, what it costs and when to
 * go is what turns an answer into a day in a plan.
 *
 * @property AttractionType $type
 * @property float|null $lat
 * @property float|null $lng
 * @property bool $is_published
 */
class Attraction extends Model
{
    /** @use HasFactory<AttractionFactory> */
    use HasFactory;

    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['name', 'summary', 'description', 'access_note', 'best_time_note'];

    protected $fillable = [
        'name',
        'slug',
        'type',
        'summary',
        'description',
        'city_id',
        'place_id',
        'lat',
        'lng',
        'visit_minutes',
        'entry_fee',
        'entry_fee_currency',
        'requires_4x4',
        'requires_permit',
        'access_note',
        'best_time_note',
        'image',
        'gallery',
        'description_source',
        'photos_source',
        'photos_attribution',
        'is_published',
    ];

    protected $casts = [
        'type' => AttractionType::class,
        'lat' => 'float',
        'lng' => 'float',
        'visit_minutes' => 'integer',
        'entry_fee' => 'decimal:2',
        // Nullable on purpose, and null means "not established" rather than
        // "no": false is a claim somebody checked, null is a blank.
        'requires_4x4' => 'boolean',
        'requires_permit' => 'boolean',
        'gallery' => 'array',
        'description_source' => ContentSource::class,
        'photos_source' => ContentSource::class,
        'is_published' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Attraction $attraction) {
            $name = $attraction->getTranslation('name', 'en', useFallbackLocale: true);

            if (blank($attraction->slug) && filled($name)) {
                $attraction->slug = Str::slug($name);
            }
        });
    }

    /**
     * The tourism location this sits in — Deadvlei is in Sossusvlei. Null for
     * something standing on its own beside a road.
     *
     * @return BelongsTo<Place, $this>
     */
    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    /**
     * The nearest town, which is what "I am in Tsumeb for two days" resolves
     * against. Not an address: a meteorite has no street.
     *
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * Whether this can appear in an answer at all. Coordinates are what make it
     * findable — "what is near here", "what is on this road" — so without them
     * it is a page, not an answer.
     */
    public function isRoutable(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /**
     * Whether its text and photographs are ours to show. A third-party
     * directory's prose stays internal no matter how the pipeline is wired —
     * see App\Enums\ContentSource.
     */
    public function isPublishable(): bool
    {
        return $this->description_source !== ContentSource::Directory
            && $this->photos_source !== ContentSource::Directory;
    }
}
