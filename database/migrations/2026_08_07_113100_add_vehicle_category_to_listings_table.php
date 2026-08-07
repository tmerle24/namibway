<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            // Only meaningful when type = 'vehicle' — self-drive rental vs. a
            // guided tour with a driver-guide included, so a traveler can tell
            // the two apart before requesting one.
            $table->string('vehicle_category')->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('vehicle_category');
        });
    }
};
