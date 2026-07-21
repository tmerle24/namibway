<?php

namespace App\Models;

use App\Enums\ListingType;
use Database\Factories\ListingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

class Listing extends Model
{
    /** @use HasFactory<ListingFactory> */
    use HasFactory, HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['name', 'description'];

    protected $fillable = [
        'type',
        'name',
        'slug',
        'description',
        'image',
        'region',
        'latitude',
        'longitude',
        'price_from',
        'price_currency',
        'is_featured',
        'is_published',
    ];

    protected $casts = [
        'type' => ListingType::class,
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
        'price_from' => 'decimal:2',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Listing $listing) {
            if (blank($listing->slug) && filled($listing->name)) {
                $listing->slug = Str::slug($listing->name);
            }
        });
    }
}
