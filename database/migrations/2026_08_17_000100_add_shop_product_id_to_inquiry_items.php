<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which site shop product a line came from when an order is placed
 * through a customer website's product-order form.
 *
 * Nullable because: (a) restaurant menu items (InquiryKind::order from a
 * namibway.com listing) have a menu_item_id instead; (b) free-text add-ons
 * have neither; (c) rows created before this migration have neither.
 *
 * See BOOKING_BEYOND_ROOMS.md §7.7. When shop_products migrate to the partner
 * (§7.6), this FK will point at the partner-scoped product, not a site product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiry_items', function (Blueprint $table): void {
            $table->foreignId('shop_product_id')
                ->nullable()
                ->after('menu_item_id')
                ->constrained('shop_products')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inquiry_items', function (Blueprint $table): void {
            $table->dropForeignIdFor(\App\Models\ShopProduct::class, 'shop_product_id');
            $table->dropColumn('shop_product_id');
        });
    }
};
