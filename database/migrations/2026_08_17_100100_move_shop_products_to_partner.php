<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products move from the site to the partner.
 *
 * ## Why the owner changes
 *
 * A product is a thing the business sells. The business is the partner. The site
 * is a presentation of the partner's work — it changes URL, template, even name
 * without the products having moved anywhere. Scoping products to the site meant
 * a partner with two sites had two product catalogues and no way to share them;
 * a product created for one site was invisible on the other. That was never the
 * intent.
 *
 * ## listing_id
 *
 * A partner-only business (shop, workshop, service) has no listing and never
 * will — `listing_id` is null for all of their products. A lodge or activity
 * operator that also sells goods can scope individual products to a property:
 * `listing_id` set means "this product is specific to this place", null means
 * it belongs to the partner's catalogue in general. Nothing reads that
 * distinction today; the column is added now so the data model is honest before
 * the UI needs it.
 *
 * ## The slug unique constraint
 *
 * The old constraint was (site_id, slug), preventing duplicate slugs within a
 * site. The new constraint is (partner_id, slug), preventing duplicates within
 * a partner's catalogue — the same slug on two of their sites would refer to the
 * same product, so the constraint now matches the ownership.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->foreignId('listing_id')->nullable()->after('partner_id')->constrained()->nullOnDelete();
        });

        // Backfill both columns from the site the product was on.
        DB::statement('
            UPDATE shop_products sp
            SET    partner_id  = s.partner_id,
                   listing_id  = s.source_listing_id
            FROM   sites s
            WHERE  sp.site_id = s.id
        ');

        DB::statement('ALTER TABLE shop_products ALTER COLUMN partner_id SET NOT NULL');

        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropUnique('shop_products_site_id_slug_unique');
            $table->dropIndex('shop_products_site_id_status_index');
            $table->unique(['partner_id', 'slug']);
            $table->index(['partner_id', 'status']);
        });

        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropColumn('site_id');
        });
    }

    public function down(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // We can restore site_id only for products whose partner still has exactly
        // one site; multi-site partners would be ambiguous, so they go to null.
        DB::statement('
            UPDATE shop_products sp
            SET    site_id = s.id
            FROM   sites s
            WHERE  sp.partner_id = s.partner_id
              AND  (SELECT COUNT(*) FROM sites s2 WHERE s2.partner_id = sp.partner_id) = 1
        ');

        DB::statement('ALTER TABLE shop_products ALTER COLUMN site_id SET NOT NULL');

        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropUnique('shop_products_partner_id_slug_unique');
            $table->dropIndex('shop_products_partner_id_status_index');
            $table->unique(['site_id', 'slug']);
            $table->index(['site_id', 'status']);
        });

        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropForeign(['listing_id']);
            $table->dropColumn(['partner_id', 'listing_id']);
        });
    }
};
