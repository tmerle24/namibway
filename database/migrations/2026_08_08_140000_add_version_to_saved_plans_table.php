<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trip plan is persisted by overwriting the whole `plan_json` document, and
 * the frontend autosaves on every edit (a deep watcher in ItinerarySection.vue,
 * not an explicit "save"). With the share link handing the same token to
 * everyone, two people — or just two tabs — editing one plan means the last
 * write silently wins and the other's changes are gone with no signal.
 *
 * `version` is the guard: a client sends back the version it loaded, and
 * KaiaController::updatePlan rejects the write with a 409 if the stored version
 * has moved on since. Existing rows start at 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_plans', function (Blueprint $table) {
            $table->unsignedInteger('version')->default(1)->after('plan_json');
        });
    }

    public function down(): void
    {
        Schema::table('saved_plans', function (Blueprint $table) {
            $table->dropColumn('version');
        });
    }
};
