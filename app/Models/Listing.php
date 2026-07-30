<?php

namespace App\Models;

use App\Enums\ListingType;
use Database\Factories\ListingFactory;
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
    public array $translatable = ['name', 'description', 'highlights'];

    protected $fillable = [
        'partner_id',
        'type',
        'name',
        'slug',
        'description',
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
    ];

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
}
