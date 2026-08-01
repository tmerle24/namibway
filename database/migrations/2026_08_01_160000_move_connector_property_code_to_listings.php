<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A partner (booking-system account) can own several bookable
     * properties/listings, each with its own property code in that system —
     * so the code belongs on the listing, not the shared partner account.
     * connector_type and connector_config (API credentials) stay on Partner
     * since those really are account-level.
     */
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->string('connector_property_code')->nullable()->after('wetu_id')
                ->comment('Property code used by this listing\'s booking connector (e.g. ResRequest property code)');
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('connector_property_code');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('connector_property_code')->nullable()->after('connector_type')
                ->comment('Property code used by the connector (e.g. ResRequest property code)');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('connector_property_code');
        });
    }
};
