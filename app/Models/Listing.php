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
        'is_featured',
        'is_published',
        'accepts_inquiries',
        'source_url',
        'claim_status',
        'website',
        'contact_email',
    ];

    protected $casts = [
        'type' => ListingType::class,
        'gallery' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'price_from' => 'decimal:2',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'accepts_inquiries' => 'boolean',
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
}
