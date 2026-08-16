<?php

namespace App\Models;

use App\Enums\ShopProductStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A product on a customer's shop section.
 *
 * @property int $id
 * @property int $site_id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 * @property float|null $price
 * @property string|null $price_text
 * @property string $currency
 * @property string|null $category
 * @property ShopProductStatus $status
 * @property array<int, int> $image_ids
 * @property string|null $instagram_post_url
 * @property int $sort
 */
class ShopProduct extends Model
{
    protected $fillable = [
        'site_id',
        'title',
        'slug',
        'description',
        'price',
        'price_text',
        'currency',
        'category',
        'status',
        'image_ids',
        'instagram_post_url',
        'sort',
    ];

    protected $casts = [
        'status' => ShopProductStatus::class,
        'price' => 'float',
        'image_ids' => 'array',
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

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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
     * The first image, or null when the product has none.
     *
     * Used for the grid thumbnail. The full image collection for a detail page
     * is loaded by the controller and keyed by id, the same as block images.
     */
    public function firstImageUrl(int $width = 600): ?string
    {
        $id = $this->image_ids[0] ?? null;

        if ($id === null) {
            return null;
        }

        $image = SiteImage::find($id);

        return $image?->thumb($width);
    }

    /**
     * The URL of this product on its site.
     *
     * Used for links from the shop block on the home page and from the shop
     * index to the detail page.
     */
    public function url(): string
    {
        return $this->site->pageUrl('shop/'.$this->slug);
    }
}
