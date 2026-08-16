<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two columns that let one `inquiries` table carry three shapes of request.
 *
 * ## `kind`
 *
 * See `App\Enums\InquiryKind`. Every row that exists today is a booking
 * request, which is what the default says, so nothing has to be backfilled and
 * every existing reader keeps reading the same thing.
 *
 * ## `arrival_time`
 *
 * A restaurant table is a date *and a time*. Until now the only front door that
 * asked for one — the customer-website enquiry form — folded it into the
 * free-text `travel_dates` column as "2026-09-01 at 19:30", with a comment
 * saying it was avoiding a migration. That was the right call for one caller
 * and the wrong one for two: a time nobody can query, sort or put on an
 * arrivals board is a string, not a fact. Both front doors now write this
 * column, and `SiteEnquiryController` stops folding it into the text.
 *
 * A `time` rather than a datetime: the date already has a column, and a second
 * one beside it is two places for the same fact to disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->string('kind', 20)->default('booking')->after('listing_id');
            $table->time('arrival_time')->nullable()->after('check_out');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn(['kind', 'arrival_time']);
        });
    }
};
