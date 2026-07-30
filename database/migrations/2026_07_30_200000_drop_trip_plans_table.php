<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Dead code path: /kaia/plans wrote here but /trip/{token} (share links,
        // PDF export) always read from saved_plans, so links created via this
        // table 404'd. The save & share feature uses saved_plans exclusively.
        Schema::dropIfExists('trip_plans');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('trip_plans', function (Blueprint $table) {
            $table->id();
            $table->string('token', 12)->unique();
            $table->json('plan_data');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
};
