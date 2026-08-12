<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where a panel remembers how somebody likes to look at a screen.
     *
     * On the user rather than in the session, because a session expires after
     * two hours and "remembered" has to mean tomorrow morning as well. One json
     * column rather than a column per setting: these are small view choices,
     * none of them is ever queried, and a migration per toggle is a bad trade.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('panel_preferences')->nullable()->after('preferred_currency');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('panel_preferences');
        });
    }
};
