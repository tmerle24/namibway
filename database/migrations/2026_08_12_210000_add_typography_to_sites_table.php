<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer website is set in.
 *
 * Keys rather than font stacks: the stack belongs in App\Sites\Typography where
 * it can be corrected once, and a column holding CSS is a column somebody will
 * eventually put a webfont URL in.
 *
 * All four are nullable, and null is a real answer — it means "the house
 * default", which is what almost every site will keep. The name's size is the
 * odd one out: null there means *computed from the length of the name*, because
 * the bar has exactly one line to fit it on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('font_display', 32)->nullable()->after('accent');
            $table->string('font_body', 32)->nullable()->after('font_display');
            $table->string('brand_font', 32)->nullable()->after('font_body');
            $table->unsignedSmallInteger('brand_size')->nullable()->after('brand_font');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['font_display', 'font_body', 'brand_font', 'brand_size']);
        });
    }
};
