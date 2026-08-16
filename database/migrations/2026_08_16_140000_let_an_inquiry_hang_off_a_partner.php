<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A request can now be addressed to a partner directly, not only to a listing.
 *
 * The shop exists for businesses that never listed on the travel platform — a
 * boutique, a craft shop — and their websites are built from the partner record
 * with no `source_listing_id` at all. Until now `inquiries.listing_id` was NOT
 * NULL and `SiteEnquiryController` answered 404 for exactly those sites, so the
 * one kind of business the product catalogue was written for could not receive
 * a single order.
 *
 * The listing stays where it is and stays the common case. What changes is that
 * it is no longer the *only* way to say who is being asked:
 *
 * - `partner_id` is filled on every row, including listing-backed ones, so
 *   "which business is this for?" is one column rather than a join through a
 *   listing that may not be there. `Inquiry::seller()` is the single reader.
 * - `listing_id` becomes nullable, and every existing row keeps its value.
 *
 * A row must name at least one of the two. That is a check constraint rather
 * than a convention, because an inquiry belonging to nobody is not a state any
 * screen can render and not one any mail can be sent about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->foreignId('partner_id')->nullable()->after('listing_id')->constrained()->nullOnDelete();
        });

        // Backfill before relaxing the column, so the constraint below is true
        // of every existing row the moment it is added.
        DB::statement('
            UPDATE inquiries
               SET partner_id = listings.partner_id
              FROM listings
             WHERE listings.id = inquiries.listing_id
               AND listings.partner_id IS NOT NULL
        ');

        Schema::table('inquiries', function (Blueprint $table) {
            $table->foreignId('listing_id')->nullable()->change();
        });

        DB::statement('
            ALTER TABLE inquiries
            ADD CONSTRAINT inquiries_have_a_seller
            CHECK (listing_id IS NOT NULL OR partner_id IS NOT NULL)
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE inquiries DROP CONSTRAINT IF EXISTS inquiries_have_a_seller');

        // A row with no listing cannot exist under the old shape. Deleting is
        // the only honest reversal — they are partner-only requests that the
        // previous schema had no way to express.
        DB::table('inquiries')->whereNull('listing_id')->delete();

        Schema::table('inquiries', function (Blueprint $table) {
            $table->foreignId('listing_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('partner_id');
        });
    }
};
