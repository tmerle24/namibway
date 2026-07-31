<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrichment_jobs', function (Blueprint $table) {
            $table->json('places_calls')->nullable()->after('fields_changed');
            $table->decimal('places_cost_estimate', 8, 4)->nullable()->after('places_calls');
        });
    }

    public function down(): void
    {
        Schema::table('enrichment_jobs', function (Blueprint $table) {
            $table->dropColumn(['places_calls', 'places_cost_estimate']);
        });
    }
};
