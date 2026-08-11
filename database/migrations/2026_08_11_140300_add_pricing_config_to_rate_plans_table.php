<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a pricing strategy needs to know, kept apart from what the rate plan is.
 *
 * A rate plan is a **product**: a name, a board basis, cancellation terms, who
 * may book it. A pricing strategy is a **calculation**. The two meet at one
 * column naming the strategy, and one column holding that strategy's own
 * parameters — the single supplement for per-person-sharing today, whatever
 * the next strategy needs tomorrow.
 *
 * JSON rather than a column per parameter, and rather than a table of its own.
 * A column per parameter is how the products table slowly becomes the pricing
 * engine: three strategies in and `rate_plans` carries a dozen columns, eleven
 * of which are null on any given row. A separate table would be a strict 1:1
 * with a join on every read for no gain in what can be expressed. The contents
 * are read through a typed value object (App\Services\Pricing\PricingConfig),
 * so "JSON" never means "anything goes" at the point of use.
 *
 * The rule this enforces: **a new pricing strategy is an extension, not a
 * schema change.**
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->json('pricing_config')->nullable()->after('pricing_strategy');
        });
    }

    public function down(): void
    {
        Schema::table('rate_plans', function (Blueprint $table) {
            $table->dropColumn('pricing_config');
        });
    }
};
