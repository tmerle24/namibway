<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $blurb
 * @property string|null $image
 * @property string $listing_region
 * @property bool $is_published
 * @property int $sort_order
 */
class Region extends Model
{
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['name', 'blurb'];

    protected $fillable = [
        'name',
        'slug',
        'blurb',
        'image',
        'listing_region',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Region $region) {
            if (blank($region->slug) && filled($region->name)) {
                $region->slug = Str::slug($region->name);
            }
        });
    }
}
