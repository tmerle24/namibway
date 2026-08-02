<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Joan Rental Services (Pty) LTD" (slug: joan-rental-services-pty-ltd) is a
 * car rental company that the Google Places import mis-tagged as
 * `accommodation`. That put it in the pool the homepage featured-pick logic
 * draws from (see HomeController::FEATURED_TYPES), surfacing a car-lot photo
 * as the homepage "cover story" image. Retag it as `vehicle`, its actual
 * category.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('listings')
            ->where('slug', 'joan-rental-services-pty-ltd')
            ->update(['type' => 'vehicle']);
    }

    public function down(): void
    {
        DB::table('listings')
            ->where('slug', 'joan-rental-services-pty-ltd')
            ->update(['type' => 'accommodation']);
    }
};
