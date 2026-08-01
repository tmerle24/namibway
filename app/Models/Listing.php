<?php

namespace App\Models;

use App\Enums\ListingType;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'wetu_id',
        'content_synced_at',
        'connector_property_code',
        'description',
        'short_description',
        'seo_description',
        'meta_title',
        'highlights',
        'image',
        'gallery',
        'pending_image',
        'pending_gallery',
        'photos_source',
        'photos_attribution',
        'photos_approved_at',
        'google_photos_expire_at',
        'terms_accepted_at',
        'terms_accepted_by',
        'region',
        'latitude',
        'longitude',
        'price_from',
        'price_currency',
        'rating',
        'rating_count',
        'is_featured',
        'is_homepage_pick',
        'is_published',
        'accepts_inquiries',
        'source_url',
        'claim_status',
        'website',
        'contact_email',
        'contact_person',
        'phone',
        'address',
        'scrape_source',
        'scrape_id',
        'scrape_data',
        'scraped_at',
        'google_photos_checked_at',
        'google_places_checked_at',
        'ai_extracted_at',
        'ntb_number',
        'facilities',
        'activities',
        'languages',
        'opening_hours',
        'enrichment_score',
        'enrichment_status',
        'confidence',
        'data_source',
        'enriched_by',
        'verified_at',
        'last_enriched_at',
    ];

    protected $casts = [
        'type' => ListingType::class,
        'gallery' => 'array',
        'pending_gallery' => 'array',
        'photos_approved_at' => 'datetime',
        'google_photos_expire_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'scrape_data' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'price_from' => 'decimal:2',
        'rating' => 'decimal:1',
        'rating_count' => 'integer',
        'is_featured' => 'boolean',
        'is_homepage_pick' => 'boolean',
        'is_published' => 'boolean',
        'accepts_inquiries' => 'boolean',
        'scraped_at' => 'datetime',
        'og_scraped_at' => 'datetime',
        'content_synced_at' => 'datetime',
        'google_photos_checked_at' => 'datetime',
        'google_places_checked_at' => 'datetime',
        'ai_extracted_at' => 'datetime',
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
     * description is rendered as raw HTML on the public listing page (to support the
     * admin panel's rich-text editor), but it's written from several places that only
     * ever produce plain text: the partner panel's plain textarea, the public
     * claim-token self-service editor, Wetu import, and the AI enrichment pipeline.
     * Routing every write through here — Spatie's translatable setTranslation() calls
     * this exact set*Attribute($value, $locale) signature — means plain text always
     * gets HTML-escaped rather than passed through HTMLPurifier: purifying plain text
     * would silently eat stray "<"/">" characters that were never meant as markup —
     * e.g. plain text like "children <12 free" is a realistic, entirely non-malicious
     * value here. Only genuine markup goes through the purifier's allow-list (which
     * strips scripts/handlers/etc); anything else is HTML-escaped as-is. Detection is
     * a real-tag-shape regex rather than strip_tags(): strip_tags() treats a "<" with
     * no later ">" as an unterminated tag and silently eats everything after it,
     * which misclassified exactly that "children <12 free" kind of value as markup.
     */
    public function setDescriptionAttribute(?string $value, string $locale): void
    {
        $this->attributes['description'] = self::sanitizeRichText($value);
    }

    public static function sanitizeRichText(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        if (! preg_match('/<\/?[a-z][a-z0-9]*(?:\s[^<>]*)?>/i', $value)) {
            return e($value);
        }

        return clean($value);
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
     * @return HasOne<EnrichmentJob, $this>
     */
    public function latestEnrichmentJob(): HasOne
    {
        return $this->hasOne(EnrichmentJob::class)->latestOfMany();
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

    /**
     * Shared by the internal Explore search endpoint (ListingController::search)
     * and the public listings API (Api\ListingController::index) so the two
     * don't drift apart on what "type", "region", "keyword" etc. mean.
     *
     * @param  Builder<Listing>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Listing>
     */
    public function scopeFilterBy(Builder $query, array $filters): Builder
    {
        $type = $filters['type'] ?? null;

        if (is_string($type) && $type !== '') {
            $query->where('type', $type);
        }

        $region = $filters['region'] ?? null;

        if (is_string($region) && $region !== '') {
            $query->where('region', 'ilike', '%'.$region.'%');
        }

        $keyword = $filters['keyword'] ?? null;

        if (is_string($keyword) && $keyword !== '') {
            $kw = '%'.mb_strtolower($keyword).'%';
            $query->where(function ($q) use ($kw) {
                $q->whereRaw('lower(cast(name as text)) like ?', [$kw])
                    ->orWhereRaw('lower(cast(description as text)) like ?', [$kw])
                    ->orWhereRaw('lower(cast(region as text)) like ?', [$kw])
                    ->orWhereRaw('lower(cast(type as text)) like ?', [$kw]);
            });
        }

        $budget = $filters['budget'] ?? null;

        if (is_string($budget)) {
            if ($budget === 'budget') {
                $query->where(function ($q) {
                    $q->where('price_from', '<', 150)->orWhereNull('price_from');
                });
            } elseif ($budget === 'mid-range') {
                $query->whereBetween('price_from', [150, 400]);
            } elseif ($budget === 'premium') {
                $query->where('price_from', '>', 400);
            }
        }

        $minRating = $filters['min_rating'] ?? null;

        if (is_string($minRating) && $minRating !== '') {
            $query->where('rating', '>=', (float) $minRating);
        }

        return $query;
    }

    public function isDueForEnrichment(): bool
    {
        return $this->enrichment_score < self::ENRICHMENT_SCORE_THRESHOLD
            || $this->last_enriched_at === null
            || $this->last_enriched_at->lt(now()->subDays(self::ENRICHMENT_REFRESH_DAYS));
    }

    public function hasPendingPhotos(): bool
    {
        return filled($this->pending_image) || filled($this->pending_gallery);
    }

    /**
     * Promotes website-scraped photos staged in pending_image/pending_gallery to the
     * live image/gallery columns, once the owner (or an admin, on their behalf) has
     * confirmed we may publish them — see EnrichmentPipeline's website image step,
     * which stages here instead of writing image/gallery directly.
     */
    public function approvePendingPhotos(): void
    {
        if (! $this->hasPendingPhotos()) {
            return;
        }

        $this->forceFill([
            'image' => $this->image ?: $this->pending_image,
            'gallery' => empty($this->gallery) ? $this->pending_gallery : $this->gallery,
            'pending_image' => null,
            'pending_gallery' => null,
            'photos_source' => 'website_scrape',
            'photos_approved_at' => now(),
        ])->save();
    }
}
