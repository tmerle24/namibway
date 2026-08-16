<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product's price becomes a number, with the free text kept beside it.
 *
 * `price` was a string on purpose — "from N$ 850", "Call for price" — because a
 * price list is written by a person and only ever read by one. That stops being
 * true the moment a visitor picks quantities: an order has to be added up, and
 * a total is a number or it is nothing.
 *
 * So the standard shape: `price` is a decimal used for the line total and the
 * order, and `price_text` carries whatever the owner wants shown instead when
 * there is no number to show. A product with only text is not orderable — it is
 * displayed, and the enquiry says "ask us" rather than pretending to a total.
 * That is the same rule the restaurant menu already follows.
 *
 * ## The existing values
 *
 * Every current row holds a string. It moves to `price_text` whole — nothing is
 * thrown away — and where it parses cleanly as a plain amount, the number is
 * lifted into `price` and the text dropped, because "N$ 350" beside a price of
 * 350 is the same fact printed twice. Anything with a qualifier ("from", "per
 * person", a range) keeps its text and gets no number, which is exactly the
 * case the two columns exist for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->string('price_text')->nullable()->after('price');
        });

        DB::statement('UPDATE shop_products SET price_text = price WHERE price IS NOT NULL');

        Schema::table('shop_products', function (Blueprint $table) {
            $table->decimal('price_amount', 10, 2)->nullable()->after('price');
            $table->char('currency', 3)->default('NAD')->after('price_amount');
        });

        // Only a bare amount, optionally behind a currency mark: "N$ 350",
        // "350", "N$ 1 200,50". A qualifier anywhere means the owner said
        // something the number cannot carry, so it stays as text alone.
        foreach (DB::table('shop_products')->whereNotNull('price_text')->get(['id', 'price_text']) as $row) {
            $text = trim((string) $row->price_text);
            $bare = preg_replace('/^(?:N\$|R|\$|€|£|ZAR|NAD|EUR|USD|GBP)\s*/iu', '', $text) ?? $text;
            $bare = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($bare));

            if ($bare === '' || ! preg_match('/^\d+(?:\.\d{1,2})?$/', $bare)) {
                continue;
            }

            DB::table('shop_products')->where('id', $row->id)->update([
                'price_amount' => (float) $bare,
                'price_text' => null,
            ]);
        }

        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropColumn('price');
        });

        Schema::table('shop_products', function (Blueprint $table) {
            $table->renameColumn('price_amount', 'price');
        });
    }

    public function down(): void
    {
        Schema::table('shop_products', function (Blueprint $table) {
            $table->renameColumn('price', 'price_amount');
        });

        Schema::table('shop_products', function (Blueprint $table) {
            $table->string('price')->nullable()->after('description');
        });

        // Back to one string, preferring what the owner typed.
        DB::statement('UPDATE shop_products SET price = COALESCE(price_text, price_amount::text)');

        Schema::table('shop_products', function (Blueprint $table) {
            $table->dropColumn(['price_amount', 'price_text', 'currency']);
        });
    }
};
