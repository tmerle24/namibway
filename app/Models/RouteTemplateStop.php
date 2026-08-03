<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One region-and-nights-range stop within a RouteTemplate's loop body —
 * excludes the Windhoek start/end bookend days, which ItineraryService
 * handles separately regardless of which template (if any) is used.
 *
 * @property int $id
 * @property int $route_template_id
 * @property string $region
 * @property int $min_nights
 * @property int $max_nights
 * @property string|null $highlights
 * @property int $sort_order
 */
class RouteTemplateStop extends Model
{
    protected $fillable = [
        'route_template_id',
        'region',
        'min_nights',
        'max_nights',
        'highlights',
        'sort_order',
    ];

    protected $casts = [
        'min_nights' => 'integer',
        'max_nights' => 'integer',
        'sort_order' => 'integer',
    ];

    /**
     * @return BelongsTo<RouteTemplate, $this>
     */
    public function routeTemplate(): BelongsTo
    {
        return $this->belongsTo(RouteTemplate::class);
    }
}
