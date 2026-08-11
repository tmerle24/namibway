<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\SiteStatus;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A customer's website.
 *
 * See the migration for why this owns its content rather than rendering a
 * listing. The short version: the flyer promises the customer keeps the content
 * if they leave, and half the customers never have a listing at all.
 *
 * @property int $id
 * @property int|null $partner_id
 * @property int|null $source_listing_id
 * @property BusinessType $business_type
 * @property string $name
 * @property string $slug
 * @property string|null $host
 * @property SiteStatus $status
 * @property string $draft_token
 * @property Carbon|null $published_at
 * @property string $accent
 * @property string $default_locale
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $whatsapp
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property array<string, string>|null $social_links
 * @property array<string, mixed>|null $imported
 */
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected $fillable = [
        'partner_id',
        'source_listing_id',
        'business_type',
        'name',
        'slug',
        'host',
        'status',
        'draft_token',
        'published_at',
        'accent',
        'default_locale',
        'contact_email',
        'contact_phone',
        'whatsapp',
        'address',
        'latitude',
        'longitude',
        'social_links',
        'imported',
    ];

    /**
     * Repeated from the migration on purpose.
     *
     * A column default is applied by the database on insert and never reaches
     * the model instance the caller is holding — so a freshly created site had
     * a null locale in memory while the row had 'en', and the very next write
     * against that instance failed. Defaults that anything reads back
     * immediately have to exist on both sides.
     *
     * @var array<string, string>
     */
    protected $attributes = [
        'status' => 'draft',
        'default_locale' => 'en',
        'accent' => 'copper',
    ];

    protected $casts = [
        'business_type' => BusinessType::class,
        'status' => SiteStatus::class,
        'published_at' => 'datetime',
        'social_links' => 'array',
        'imported' => 'array',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    protected static function booted(): void
    {
        static::creating(function (Site $site) {
            if (blank($site->slug) && filled($site->name)) {
                $site->slug = Str::slug($site->name);
            }

            // A draft is unreachable without this, so it can never be absent.
            // 40 hex characters of randomness: this is the only thing standing
            // between a page written about somebody's business and the open
            // internet, before they have agreed to it existing.
            if (blank($site->draft_token)) {
                $site->draft_token = Str::random(40);
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
     * The listing this site's content was copied from, where there was one.
     *
     * Read for provenance and for the booking block — never to render content.
     * A page that reads through here at request time would undo the ownership
     * promise this whole model exists to keep.
     *
     * @return BelongsTo<Listing, $this>
     */
    public function sourceListing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'source_listing_id');
    }

    /**
     * @return HasMany<SitePage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(SitePage::class);
    }

    /**
     * @return HasOne<SitePage, $this>
     */
    public function homePage(): HasOne
    {
        return $this->hasOne(SitePage::class)->where('is_home', true);
    }

    /**
     * @return HasMany<SiteImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(SiteImage::class);
    }

    public function isPublished(): bool
    {
        return $this->status->isPublished();
    }

    /** Where this site's images live on the bucket. */
    public function mediaPrefix(): string
    {
        return trim((string) config('sites.media_prefix', 'sites'), '/').'/'.$this->slug;
    }

    /**
     * The address to hand somebody.
     *
     * A published site is its host. A draft is the path fallback with its
     * token, because a draft that can be reached by guessing a subdomain is not
     * a draft — see the migration.
     */
    public function publicUrl(): string
    {
        if ($this->isPublished() && filled($this->host)) {
            return 'https://'.$this->host;
        }

        $path = '/'.trim((string) config('sites.path_prefix', '_sites'), '/').'/'.$this->slug;

        return url($path).($this->isPublished() ? '' : '?preview='.$this->draft_token);
    }

    /**
     * The default host for a newly created site, or null where no wildcard
     * domain is configured — which is local development, CI, and any period
     * before the DNS exists. A site without a host is reviewed at the path
     * fallback and is otherwise entirely normal.
     */
    public static function defaultHostFor(string $slug): ?string
    {
        $suffix = trim((string) config('sites.host_suffix'), '.');

        return $suffix === '' ? null : $slug.'.'.$suffix;
    }
}
