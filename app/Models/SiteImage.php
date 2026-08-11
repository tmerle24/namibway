<?php

namespace App\Models;

use App\Enums\ContentSource;
use App\Support\MediaUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A picture belonging to a site — bytes we copied, in the site's own prefix.
 *
 * @property int $id
 * @property int $site_id
 * @property string $key
 * @property int|null $width
 * @property int|null $height
 * @property string|null $alt
 * @property ContentSource|null $content_source
 * @property bool $prospect_only
 * @property string|null $attribution
 * @property int|null $source_listing_id
 * @property int $sort
 */
class SiteImage extends Model
{
    protected $fillable = [
        'site_id',
        'key',
        'width',
        'height',
        'alt',
        'content_source',
        'prospect_only',
        'attribution',
        'source_listing_id',
        'sort',
    ];

    protected $casts = [
        'content_source' => ContentSource::class,
        'prospect_only' => 'boolean',
        'width' => 'integer',
        'height' => 'integer',
        'sort' => 'integer',
    ];

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** The stored original. Nothing should render this — ask for a width. */
    public function url(): string
    {
        return Storage::disk('r2')->url($this->key);
    }

    /**
     * A right-sized URL for a slot this many CSS pixels wide.
     *
     * Root-relative on purpose (MediaUrl returns /thumbs/…): the browser has
     * already opened a connection to this host, and on a slow mobile link a
     * second DNS lookup and TLS handshake costs more than any cache sharing an
     * absolute URL would buy back.
     */
    public function thumb(int $width): string
    {
        return MediaUrl::thumb($this->url(), $width) ?? $this->url();
    }

    public function srcset(int $width): ?string
    {
        return MediaUrl::srcset($this->url(), $width);
    }
}
