<?php

namespace App\Models;

use App\Enums\RouteTripType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Translatable\HasTranslations;

/**
 * A curated "classic Namibia route" (e.g. "Classic Safari Loop") that Kaia's
 * itinerary generator picks from and adapts instead of inventing a route
 * freeform — see App\Services\Kaia\ItineraryService::matchingRouteTemplates().
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property RouteTripType $trip_type
 * @property int $min_nights
 * @property int $max_nights
 * @property string|null $notes
 * @property bool $is_published
 * @property int $sort_order
 */
class RouteTemplate extends Model
{
    use HasTranslations;

    /** @var array<int, string> */
    public array $translatable = ['name'];

    protected $fillable = [
        'name',
        'slug',
        'trip_type',
        'min_nights',
        'max_nights',
        'notes',
        'is_published',
        'sort_order',
    ];

    protected $casts = [
        'trip_type' => RouteTripType::class,
        'min_nights' => 'integer',
        'max_nights' => 'integer',
        'is_published' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * @return HasMany<RouteTemplateStop, $this>
     */
    public function stops(): HasMany
    {
        return $this->hasMany(RouteTemplateStop::class)->orderBy('sort_order');
    }

    protected static function booted(): void
    {
        static::saving(function (RouteTemplate $template) {
            if (blank($template->slug) && filled($template->name)) {
                $template->slug = Str::slug($template->name);
            }
        });
    }
}
