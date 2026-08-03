<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->timestamp('connector_verified_at')->nullable()->after('connector_config')
                ->comment('Set once staff has checked the connector_config credentials actually work — ConnectorFactory refuses to use them for live bookings until then.');
        });

        // Every connector_type/connector_config currently on file was necessarily
        // entered by staff via Filament — there was no owner-facing way to set one
        // before this column existed. Backfill them as already-verified so this
        // migration doesn't silently cut off live bookings for partners already
        // working in production the moment it deploys.
        DB::table('partners')->whereNotNull('connector_type')->update([
            'connector_verified_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('connector_verified_at');
        });
    }
};
