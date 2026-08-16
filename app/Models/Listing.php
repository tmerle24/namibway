<?php

namespace App\Models;

use App\Enums\ConnectorType;
use App\Enums\ContentSource;
use App\Enums\InquiryKind;
use App\Enums\ListingType;
use App\Enums\PriceUnit;
use App\Enums\VehicleCategory;
use App\Enums\VehicleClass;
use App\Support\CountrySettings;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * @property-read float|null $distance_km Only present when ListingController::search()
 *  added it via a raw SELECT (a reference city was resolvable) — never a real column.
 */
class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory, HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['name', 'description', 'short_description', 'highlights'];

    protected $fillable = [
        'partner_id',
        'type',
        'vehicle_category',
        'vehicle_class',
        'name',
        'slug',
        'wetu_id',
        'content_synced_at',
        'connector_property_code',
        'description',
        'short_description',
        'description_source',
        'seo_description',
        'meta_title',
        'highlights',
        'image',
        'gallery',
        'pending_image',
        'pending_gallery',
        'pending_photos_source',
        'photos_source',
        'photos_attribution',
        'photos_approved_at',
        'google_photos_expire_at',
        'terms_accepted_at',
        'terms_accepted_by',
        'city_id',
        'latitude',
        'longitude',
        'price_from',
        'price_currency',
        'price_unit',
        'duration_minutes',
        'rating',
        'rating_count',
        'is_featured',
        'is_homepage_pick',
        'is_published',
        'accepts_inquiries',
        'accepts_table_reservations',
        'accepts_orders',
        'source_url',
        'claim_status',
        'website',
        'social_links',
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
        'commission_rate',
        'deposit_rate',
    ];

    protected $casts = [
        'type' => ListingType::class,
        'commission_rate' => 'float',
        'deposit_rate' => 'float',
        'vehicle_category' => VehicleCategory::class,
        'vehicle_class' => VehicleClass::class,
        'gallery' => 'array',
        'social_links' => 'array',
        'pending_gallery' => 'array',
        'pending_photos_source' => ContentSource::class,
        'photos_source' => ContentSource::class,
        'description_source' => ContentSource::class,
        'photos_approved_at' => 'datetime',
        'google_photos_expire_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'scrape_data' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'price_from' => 'decimal:2',
        'price_unit' => PriceUnit::class,
        'duration_minutes' => 'integer',
        'rating' => 'decimal:1',
        'rating_count' => 'integer',
        'is_featured' => 'boolean',
        'is_homepage_pick' => 'boolean',
        'is_published' => 'boolean',
        'accepts_inquiries' => 'boolean',
        'accepts_table_reservations' => 'boolean',
        'accepts_orders' => 'boolean',
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

            // Native listings have no external property code to enter — the
            // slug doubles as the identifier NativeConnector resolves the
            // Listing from (see App\Connectors\Native\NativeConnector).
            if (blank($listing->connector_property_code)
                && $listing->partner?->connector_type === ConnectorType::Native) {
                $listing->connector_property_code = $listing->slug;
            }

            // Text written through any human-facing path — the admin panel, the
            // partner panel, the Excel importer, the owner's own editor — is the
            // top of the content ladder. Marking it here rather than at each of
            // those call sites means a hand-written description can never be
            // mistaken for leftover scraped text and regenerated over. Writers
            // that know their own provenance set both fields together, and are
            // left alone.
            if ($listing->isDirty('description') && ! $listing->isDirty('description_source')) {
                $listing->description_source = ContentSource::Manual;
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
     * @return BelongsTo<City, $this>
     */
    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }

    /**
     * The political region this listing belongs to, derived from its city —
     * kept as a plain string here (rather than requiring every caller to
     * chain ->city->region->name) so the AI itinerary engine, JSON API
     * responses, and admin filters that already read Listing::region as a
     * string keep working unchanged after city_id replaced the old free-text
     * region column.
     */
    public function getRegionAttribute(): ?string
    {
        return $this->city?->region?->name;
    }

    /**
     * @return HasMany<Inquiry, $this>
     */
    public function inquiries(): HasMany
    {
        return $this->hasMany(Inquiry::class);
    }

    /**
     * What a restaurant sells, in menu order. Empty for everything else, and
     * empty for a restaurant nobody has entered a menu for — which is every
     * restaurant until somebody does, and which the page handles by offering a
     * table and no order tab.
     *
     * @return HasMany<MenuItem, $this>
     */
    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * The shapes of request this property is willing to take online.
     *
     * One place, because four readers ask the same question and would
     * otherwise each answer it slightly differently: the listing page decides
     * which tabs to draw, the controller decides which kind a POST may claim,
     * the panels show what the toggles are doing, and the tests assert it.
     *
     * Three rules, in order:
     *
     * - `accepts_inquiries` is above all of this. Off means the property is
     *   asked for nothing, whatever the restaurant toggles say.
     * - Anything that is not a restaurant is asked for a stay, full stop —
     *   the two restaurant columns exist on every row but mean nothing there.
     * - Ordering additionally needs something to order. A toggle switched on
     *   over an empty menu is a promise the page cannot keep, so it counts as
     *   off until a dish exists.
     *
     * @return array<int, InquiryKind>
     */
    public function requestKinds(): array
    {
        if (! $this->accepts_inquiries) {
            return [];
        }

        if ($this->type !== ListingType::Restaurant) {
            return [InquiryKind::Booking];
        }

        $kinds = [];

        if ($this->accepts_table_reservations) {
            $kinds[] = InquiryKind::TableReservation;
        }

        if ($this->accepts_orders && $this->menuItems()->available()->exists()) {
            $kinds[] = InquiryKind::Order;
        }

        return $kinds;
    }

    /** Whether this property can be asked for anything at all right now. */
    public function acceptsRequests(): bool
    {
        return $this->requestKinds() !== [];
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * The products this property sells its rooms under — board basis,
     * eligibility, and how a night becomes an amount. See BOOKING_SYSTEM.md.
     *
     * A property with none is a valid state: every reader falls back to the
     * room type's own rate, which is what it did before rate plans existed.
     *
     * @return HasMany<RatePlan, $this>
     */
    public function ratePlans(): HasMany
    {
        return $this->hasMany(RatePlan::class);
    }

    /**
     * The currency this property sells in.
     *
     * Read from a room type rather than from the country, because a room type
     * carries its own column and that is what a price is actually stored
     * against. A screen that took the country's currency while the price came
     * from the room type would print the wrong symbol in front of a real
     * number — a small bug with an expensive shape.
     *
     * Falls back to the country's currency for a property with no room types
     * yet, which is every property before somebody sets it up.
     */
    public function sellingCurrency(): string
    {
        $room = $this->bookableUnits()
            ->where('is_active', true)
            ->whereNotNull('currency')
            ->orderBy('id')
            ->first();

        return $room === null
            ? CountrySettings::for($this)->currency()
            : CountrySettings::currencyForBookableUnit($room);
    }

    /**
     * What this property has, from the shared catalogue.
     *
     * @return BelongsToMany<Amenity, $this>
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class)->withTimestamps();
    }

    /**
     * What to show as this property's amenities.
     *
     * **Structured beats free text, completely.** `facilities` is what a
     * directory said or what an AI read off a website; the catalogue entries
     * are what somebody who owns the property chose. Once there is one of the
     * latter, the former stops being an answer — not merged with it, because
     * merging would put "Pool" beside "Swimming pool" and make the owner's own
     * list look careless.
     *
     * The free text is kept rather than deleted: it is a record of what a
     * source claimed, the same way scrape_data is, and a listing that has
     * never been claimed still needs something to show.
     *
     * This is the content-source ladder from CLAUDE.md — partner over scrape —
     * applied to amenities.
     *
     * @return array<int, string>
     */
    public function amenityList(): array
    {
        $chosen = $this->amenities->sortBy('sort')->pluck('name')->all();

        if ($chosen !== []) {
            return array_values($chosen);
        }

        $facilities = $this->facilities ?? [];

        return array_values(array_filter($facilities, fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /** Whether somebody has said what this property has, rather than a scraper guessing. */
    public function hasChosenAmenities(): bool
    {
        return $this->amenities()->exists();
    }

    /**
     * Who this property charges differently — adults, children, infants, with
     * the age bands it publishes. Empty until a property prices by guests, and
     * empty is the right state for one that never does.
     *
     * @return HasMany<GuestCategory, $this>
     */
    public function guestCategories(): HasMany
    {
        return $this->hasMany(GuestCategory::class);
    }

    /**
     * @return HasMany<BookableUnit, $this>
     */
    public function bookableUnits(): HasMany
    {
        return $this->hasMany(BookableUnit::class);
    }

    /**
     * Stays at this property. Distinct from inquiries(): an inquiry is a
     * request that may never become a stay, and a walk-in is a stay that was
     * never a request. See CLAUDE.md, "How a confirmed Inquiry becomes a
     * Reservation".
     *
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
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
     * Owner-contact thread, scoped to the partner (not this listing) so that
     * an owner with several properties has one conversation, not one per
     * listing — see PartnerMessage.
     *
     * @return HasMany<PartnerMessage, $this>
     */
    public function partnerMessages(): HasMany
    {
        return $this->hasMany(PartnerMessage::class, 'partner_id', 'partner_id');
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

        $vehicleCategory = $filters['vehicle_category'] ?? null;

        if (is_string($vehicleCategory) && $vehicleCategory !== '') {
            $query->where('vehicle_category', $vehicleCategory);
        }

        $vehicleClass = $filters['vehicle_class'] ?? null;

        if (is_string($vehicleClass) && $vehicleClass !== '') {
            $query->where('vehicle_class', $vehicleClass);
        }

        $region = $filters['region'] ?? null;

        if (is_string($region) && $region !== '') {
            $query->whereHas('city', fn ($q) => $q->where('name', 'ilike', '%'.$region.'%')
                ->orWhereHas('region', fn ($q2) => $q2->where('name', 'ilike', '%'.$region.'%')));
        }

        // Separate from $region above (which also matches city names, for the
        // AI chat's free-text "region" intent) — this is the classic Explore
        // page's dedicated City select, an exact-ish match on city name only.
        $city = $filters['city'] ?? null;

        if (is_string($city) && $city !== '') {
            $query->whereHas('city', fn ($q) => $q->where('name', 'ilike', '%'.$city.'%'));
        }

        $keyword = $filters['keyword'] ?? null;

        if (is_string($keyword) && $keyword !== '') {
            $kw = '%'.mb_strtolower($keyword).'%';
            $query->where(function ($q) use ($kw) {
                $q->whereRaw('lower(cast(name as text)) like ?', [$kw])
                    ->orWhereRaw('lower(cast(description as text)) like ?', [$kw])
                    ->orWhereRaw('lower(cast(type as text)) like ?', [$kw])
                    // facilities is a plain (untranslated) string array — e.g. "Pool",
                    // "WiFi" — so a traveler can find listings by amenity through the
                    // same keyword box rather than needing a dedicated filter for it.
                    ->orWhereRaw('lower(cast(facilities as text)) like ?', [$kw])
                    ->orWhereHas('city', fn ($q2) => $q2->whereRaw('lower(cast(name as text)) like ?', [$kw])
                        ->orWhereHas('region', fn ($q3) => $q3->whereRaw('lower(cast(name as text)) like ?', [$kw])));
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
     * Staged photos an approval could actually publish.
     *
     * Directory photos are staged too — they are useful internally, so an admin
     * matching a listing can see what the property looks like — but they are
     * someone else's photography and no approval of ours can license them.
     */
    public function hasApprovablePhotos(): bool
    {
        return $this->hasPendingPhotos()
            && ($this->pending_photos_source ?? ContentSource::WebsiteScrape)->publishable();
    }

    /**
     * Promotes website-scraped photos staged in pending_image/pending_gallery to the
     * live image/gallery columns, once the owner (or an admin, on their behalf) has
     * confirmed we may publish them — see EnrichmentPipeline's website image step,
     * which stages here instead of writing image/gallery directly.
     */
    public function approvePendingPhotos(): void
    {
        // Not merely "nothing staged": a listing can hold staged directory
        // photos, and consent from the owner cannot license someone else's
        // photography. Those stay pending as internal reference.
        if (! $this->hasApprovablePhotos()) {
            return;
        }

        $source = $this->pending_photos_source ?? ContentSource::WebsiteScrape;

        $this->forceFill([
            'image' => $this->image ?: $this->pending_image,
            'gallery' => empty($this->gallery) ? $this->pending_gallery : $this->gallery,
            'pending_image' => null,
            'pending_gallery' => null,
            'pending_photos_source' => null,
            'photos_source' => $source,
            'photos_approved_at' => now(),
        ])->save();
    }
}
