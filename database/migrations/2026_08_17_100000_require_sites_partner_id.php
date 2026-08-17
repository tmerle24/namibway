<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `sites.partner_id` becomes required.
 *
 * A site must belong to somebody — the enquiry controller has asserted this
 * since the column was added, and a site with no partner has no confirm/decline
 * mail path and no one to notify. Making it NOT NULL at the schema level removes
 * the nullable guard from every caller and lets the relation shed its `|null`.
 *
 * The cascade on delete replaces nullOnDelete: a partner that is deleted takes
 * their site with them, which is the correct behaviour — an orphaned site with
 * no owner is not a site anyone can manage.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Data guard: no row should be null in production, but delete any that
        // slipped through rather than leaving them to fail the NOT NULL alter.
        DB::table('sites')->whereNull('partner_id')->delete();

        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
        });

        DB::statement('ALTER TABLE sites ALTER COLUMN partner_id SET NOT NULL');

        Schema::table('sites', function (Blueprint $table) {
            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
        });

        DB::statement('ALTER TABLE sites ALTER COLUMN partner_id DROP NOT NULL');

        Schema::table('sites', function (Blueprint $table) {
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });
    }
};
