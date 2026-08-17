<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an order — see the `inquiry_items` migration for why every value
 * here is a copy rather than a lookup.
 *
 * Written by `App\Services\Booking\MenuOrder` (namibway.com restaurant orders)
 * and `App\Services\Booking\SiteOrder` (customer-website product and menu orders).
 * Both price from the database — never from what the browser posted.
 *
 * @property int $id
 * @property int $inquiry_id
 * @property int|null $menu_item_id
 * @property int|null $shop_product_id
 * @property string $name
 * @property int $quantity
 * @property float $unit_price
 * @property float $line_total
 * @property string $currency
 */
class InquiryItem extends Model
{
    protected $fillable = [
        'inquiry_id',
        'menu_item_id',
        'shop_product_id',
        'name',
        'quantity',
        'unit_price',
        'line_total',
        'currency',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'float',
        'line_total' => 'float',
    ];

    /** @return BelongsTo<Inquiry, $this> */
    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    /**
     * The menu item this line was taken from, where it still exists. Null once
     * the restaurant deletes the dish — the order keeps its own record of what
     * was ordered and what it cost.
     *
     * @return BelongsTo<MenuItem, $this>
     */
    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class);
    }

    /**
     * The site shop product this line came from, where set. Null for restaurant
     * orders (those carry menu_item_id) and for lines without a source product.
     *
     * @return BelongsTo<ShopProduct, $this>
     */
    public function shopProduct(): BelongsTo
    {
        return $this->belongsTo(ShopProduct::class);
    }
}
