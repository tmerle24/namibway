<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->string('connector_type')->nullable()->after('facebook')
                ->comment('resconnect | nightsbridge | hopecloud | manual');
            $table->string('connector_property_code')->nullable()->after('connector_type')
                ->comment('Property code used by the connector (e.g. ResRequest property code)');
            $table->json('connector_config')->nullable()->after('connector_property_code')
                ->comment('Connector-specific config (api_key, base_url, etc.) — store encrypted at rest');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['connector_type', 'connector_property_code', 'connector_config']);
        });
    }
};
