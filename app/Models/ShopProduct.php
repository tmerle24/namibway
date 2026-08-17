<?php

namespace App\Models;

use App\Enums\ShopProductStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * A product on a partner's shop.
 *
 * Owned by the partner, not the site. A partner's catalogue is the same across
 * all of their sites; the site is a presentation layer, not the owner.
 *
 * @property int $id
 * @property int $partner_id
 * @property int|null $listing_id Property-specific products set this; general catalogue products leave it null.
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property float|null $price
 * @property string|null $price_text
 * @property string $currency
 * @property string|null $category
 * @property ShopProductStatus $status
 * @property string|null $instagram_post_url
 * @property int $sort
 */
class ShopProduct extends Model
{
    protected $fillable = [
        'partner_id',
        'listing_id',
        'title',
        'slug',
        'description',
        'price',
        'price_text',
        'currency',
        'category',
        'status',
        'instagram_post_url',
        'sort',
    ];

    protected $casts = [
        'status' => ShopProductStatus::class,
        'price' => 'float',
        'sort' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (ShopProduct $product) {
            if (blank($product->slug)) {
                $product->slug = Str::slug($product->title);
            }
        });
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /**
     * Ordered product images via the shop_product_images pivot.
     *
     * @return BelongsToMany<SiteImage, $this>
     */
    public function images(): BelongsToMany
    {
        return $this->belongsToMany(SiteImage::class, 'shop_product_images')
            ->withPivot('sort')
            ->orderByPivot('sort');
    }

    /** @param Builder<ShopProduct> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ShopProductStatus::Published);
    }

    /**
     * Products a visitor can actually put a quantity against.
     *
     * A price is what makes something orderable. Without one the product still
     * appears in the shop — it is a catalogue as much as a till — but an order
     * form cannot total it, so it is not offered with a stepper.
     *
     * @param  Builder<ShopProduct>  $query
     */
    public function scopeOrderable(Builder $query): void
    {
        $query->published()->whereNotNull('price');
    }

    /**
     * What to print where the price goes.
     *
     * The number when there is one, the owner's own words otherwise — "from
     * N$ 850", "Call for price". Never both: a figure and a sentence saying
     * something different about the same product is how a shop loses an
     * argument with a customer.
     */
    public function priceLabel(): ?string
    {
        if ($this->price !== null) {
            return $this->currency.' '.number_format($this->price, 2);
        }

        return filled($this->price_text) ? $this->price_text : null;
    }

    /**
     * The first image URL, or null when the product has none.
     *
     * Loads via the relationship — call with an already-eager-loaded model
     * to avoid an extra query per product.
     */
    public function firstImageUrl(int $width = 600): ?string
    {
        return $this->images->first()?->thumb($width);
    }
}
