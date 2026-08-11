<?php

namespace App\Models;

use Database\Factories\SitePageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One page of a customer website. Slice 1 generates exactly one.
 *
 * @property int $id
 * @property int $site_id
 * @property string $slug
 * @property string $locale
 * @property string|null $title
 * @property string|null $meta_description
 * @property bool $is_home
 * @property int $sort
 */
class SitePage extends Model
{
    /** @use HasFactory<SitePageFactory> */
    use HasFactory;

    protected $fillable = [
        'site_id',
        'slug',
        'locale',
        'title',
        'meta_description',
        'is_home',
        'sort',
    ];

    protected $casts = [
        'is_home' => 'boolean',
        'sort' => 'integer',
    ];

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<SiteBlock, $this>
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(SiteBlock::class)->orderBy('sort');
    }

    /**
     * Enabled blocks, in order. Whether each has anything to show is a further
     * question the renderer answers — see SiteController::shouldRender, which
     * also drops any type that has since left the registry rather than letting
     * a withdrawn class 500 a live customer's page.
     *
     * @return HasMany<SiteBlock, $this>
     */
    public function renderableBlocks(): HasMany
    {
        return $this->blocks()->where('is_enabled', true);
    }
}
