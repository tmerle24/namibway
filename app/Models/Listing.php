<?php

namespace App\Models;

use App\Enums\ListingType;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory, HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['name', 'description', 'short_description', 'highlights'];

    protected $fillable = [
        'partner_id',
        'type',
        'name',
        'slug',
        'description',
        'short_description',
        'seo_description',
        'meta_title',
        'highlights',
        'image',
        'gallery',
        'region',
        'latitude',
        'longitude',
        'price_from',
        'price_currency',
        'rating',
        'rating_count',
        'is_featured',
        'is_published',
        'accepts_inquiries',
        'source_url',
        'claim_status',
        'website',
        'contact_email',
        'phone',
        'address',
        'scrape_source',
        'scrape_id',
        'scrape_data',
        'scraped_at',
        'google_photos_checked_at',
        'ntb_number',
        'facilities',
        'activities',
        'languages',
        'opening_hours',
        'enrichment_score',
        'enrichment_status',
        'confidence',
        'data_source',
        'verified_at',
        'last_enriched_at',
    ];

    protected $casts = [
        'type' => ListingType::class,
        'gallery' => 'array',
        'scrape_data' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'price_from' => 'decimal:2',
        'rating' => 'decimal:1',
        'rating_count' => 'integer',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'accepts_inquiries' => 'boolean',
        'scraped_at' => 'datetime',
        'og_scraped_at' => 'datetime',
        'content_synced_at' => 'datetime',
        'google_photos_checked_at' => 'datetime',
        'facilities' => 'array',
        'activities' => 'array',
        'languages' => 'array',
        'opening_hours' => 'array',
        'enrichment_score' => 'integer',
        'confidence' => 'integer',
        'verified_at' => 'datetime',
        'last_enriched_at' => 'datetime',
    ];

    /** Listings due for automatic enrichment: never enriched, low completion, or stale. */
    private const ENRICHMENT_SCORE_THRESHOLD = 80;

    private const ENRICHMENT_REFRESH_DAYS = 90;

    protected static function booted(): void
    {
        static::saving(function (Listing $listing) {
            if (blank($listing->slug) && filled($listing->name)) {
                $listing->slug = Str::slug($listing->name);
            }
        });
    }

    /**
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return HasMany<Inquiry, $this>
     */
    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * @return HasMany<EnrichmentJob, $this>
     */
    public function enrichmentJobs(): HasMany
    {
        return $this->hasMany(EnrichmentJob::class);
    }

    /**
     * @return HasMany<ListingFieldStatus, $this>
     */
    public function fieldStatuses(): HasMany
    {
        return $this->hasMany(ListingFieldStatus::class);
    }

    /**
     * @param  Builder<Listing>  $query
     * @return Builder<Listing>
     */
    public function scopeOrderByEnrichmentPriority(Builder $query): Builder
    {
        return $query->orderByRaw('enrichment_score ASC, last_enriched_at ASC NULLS FIRST');
    }

    public function isDueForEnrichment(): bool
    {
        return $this->enrichment_score < self::ENRICHMENT_SCORE_THRESHOLD
            || $this->last_enriched_at === null
            || $this->last_enriched_at->lt(now()->subDays(self::ENRICHMENT_REFRESH_DAYS));
    }
}
