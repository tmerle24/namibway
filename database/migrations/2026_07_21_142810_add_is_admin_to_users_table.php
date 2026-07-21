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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
        });

        // Deliberately no backfill here — there's no reliable way to tell an
        // existing Filament admin apart from a public Login/Register/social
        // signup. Grant access explicitly per environment, e.g.:
        // php artisan tinker --execute="App\Models\User::where('email', '...')->update(['is_admin' => true]);"
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_admin');
        });
    }
};
