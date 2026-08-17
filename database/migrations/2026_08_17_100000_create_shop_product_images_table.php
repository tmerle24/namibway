<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_image_id')->constrained()->cascadeOnDelete();
            $table->integer('sort')->default(0);
            $table->unique(['shop_product_id', 'site_image_id']);
            $table->timestamps();
        });

        // Migrate existing image_ids JSON arrays into the pivot table.
        $products = DB::table('shop_products')
            ->whereRaw("image_ids::text != '[]'")
            ->get(['id', 'image_ids']);

        foreach ($products as $product) {
            $ids = json_decode($product->image_ids, true) ?? [];

            foreach ($ids as $sort => $siteImageId) {
                if (! DB::table('site_images')->where('id', $siteImageId)->exists()) {
                    continue;
                }

                DB::table('shop_product_images')->insertOrIgnore([
                    'shop_product_id' => $product->id,
                    'site_image_id' => (int) $siteImageId,
                    'sort' => (int) $sort,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropColumn('image_ids');
        });
    }

    public function down(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->json('image_ids')->default('[]');
        });

        $rows = DB::table('shop_product_images')
            ->orderBy('shop_product_id')
            ->orderBy('sort')
            ->get(['shop_product_id', 'site_image_id']);

        $byProduct = [];
        foreach ($rows as $row) {
            $byProduct[$row->shop_product_id][] = $row->site_image_id;
        }

        foreach ($byProduct as $productId => $ids) {
            DB::table('shop_products')
                ->where('id', $productId)
                ->update(['image_ids' => json_encode($ids)]);
        }

        Schema::dropIfExists('shop_product_images');
    }
};
