<?php

namespace App\Models;

use App\Enums\BusinessType;
use App\Enums\DomainStatus;
use App\Enums\SiteStatus;
use App\Support\MediaUrl;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
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
 * @property string|null $custom_domain
 * @property DomainStatus|null $domain_status
 * @property Carbon|null $domain_checked_at
 * @property string|null $domain_message
 * @property SiteStatus $status
 * @property string $draft_token
 * @property Carbon|null $published_at
 * @property string $accent
 * @property string|null $font_display
 * @property string|null $font_body
 * @property string|null $brand_font
 * @property int|null $brand_size
 * @property int|null $brand_size_mobile
 * @property string|null $logo_key
 * @property string $default_locale
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $whatsapp
 * @property array<string, mixed>|null $action_buttons
 * @property string|null $brand_name
 * @property string|null $address
 * @property string|null $latitude
 * @property string|null $longitude
 * @property array<string, string>|null $social_links
 * @property string|null $legal_privacy
 * @property string|null $legal_imprint
 * @property string|null $legal_copyright
 * @property Carbon|null $terms_accepted_at
 * @property string|null $terms_accepted_by
 * @property string|null $terms_version
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
        'custom_domain',
        'domain_status',
        'domain_checked_at',
        'domain_message',
        'status',
        'draft_token',
        'published_at',
        'accent',
        'font_display',
        'font_body',
        'brand_font',
        'brand_size',
        'brand_size_mobile',
        'logo_key',
        'default_locale',
        'contact_email',
        'contact_phone',
        'whatsapp',
        'action_buttons',
        'brand_name',
        'address',
        'latitude',
        'longitude',
        'social_links',
        'legal_privacy',
        'legal_imprint',
        'legal_copyright',
        'terms_accepted_at',
        'terms_accepted_by',
        'terms_version',
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
        'domain_status' => DomainStatus::class,
        'domain_checked_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
        'social_links' => 'array',
        'imported' => 'array',
        'brand_size' => 'integer',
        'brand_size_mobile' => 'integer',
        'action_buttons' => 'array',
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

    /**
     * @return HasMany<ShopProduct, $this>
     */
    public function shopProducts(): HasMany
    {
        return $this->hasMany(ShopProduct::class);
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
        return $this->pageUrl();
    }

    /**
     * The name as the bar sets it, which is not always the name of the
     * business.
     *
     * "Ongombo West #56 Hunting Safari" is the registered name and belongs in
     * the page title and the legal notice; in a 64px bar beside a menu it wraps
     * where the browser decides, which was "…Hunting / Safari". So `brand_name`
     * is a separate field: shorter if they want, and a line break in it is
     * honoured (the bar allows two lines and no more), while `name` stays the
     * name everywhere it is read as one.
     */
    public function brandName(): string
    {
        return filled($this->brand_name) ? (string) $this->brand_name : (string) $this->name;
    }

    /**
     * The owner's mark, sized for the slot it goes in — or null, in which case
     * the name is set in type instead. Root-relative like every other image on
     * these pages; see SiteImage::thumb().
     */
    public function logoUrl(int $width = 400): ?string
    {
        if (blank($this->logo_key)) {
            return null;
        }

        $url = Storage::disk('r2')->url((string) $this->logo_key);

        return MediaUrl::thumb($url, $width) ?? $url;
    }

    /**
     * The address of one page of this site — the home page when given nothing.
     *
     * Subpages have to go through here rather than be concatenated onto
     * publicUrl(), because a draft is read at `?preview=<token>` and appending
     * a path after the query string produces an address that resolves to
     * nothing.
     */
    public function pageUrl(?string $pageSlug = null): string
    {
        $suffix = filled($pageSlug) ? '/'.ltrim((string) $pageSlug, '/') : '';

        // Their own domain wins once it is actually live. Before that it has no
        // certificate, so linking to it would hand somebody an address their
        // browser refuses.
        if ($this->isPublished() && $this->servesCustomDomain()) {
            return 'https://'.$this->custom_domain.$suffix;
        }

        if ($this->isPublished() && filled($this->host)) {
            return 'https://'.$this->host.$suffix;
        }

        $path = '/'.trim((string) config('sites.path_prefix', '_sites'), '/').'/'.$this->slug.$suffix;

        return url($path).($this->isPublished() ? '' : '?preview='.$this->draft_token);
    }

    /** Whether this site should be served on the customer's own domain yet. */
    public function servesCustomDomain(): bool
    {
        return filled($this->custom_domain) && $this->domain_status?->isServable() === true;
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
