<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The name in the bar, sized separately for a phone and for a desktop.
 *
 * One number could not serve both: the size that keeps a long name on one line
 * beside a burger on a 360px screen is visibly undersized on a laptop, where
 * the same bar is three times as wide. Both are nullable and null still means
 * "work it out from the length of the name".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedSmallInteger('brand_size_mobile')->nullable()->after('brand_size');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('brand_size_mobile');
        });
    }
};
