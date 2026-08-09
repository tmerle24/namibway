<?php

use App\Models\SavedPlan;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Booking had no notion of who was doing it. `trips` and `inquiries` carried a
 * name and an email typed into a form, nothing else — so the rules CLAUDE.md
 * states ("booking needs an account", "only the plan's creator can book") had
 * no column to check, and the one-active-request-per-traveler gate keyed on the
 * submitted email, which the same person can simply change.
 *
 * - `trips.user_id` / `inquiries.user_id`: the account that sent the request.
 *   Nullable because every row that predates this has no account behind it —
 *   they stay readable, they just can't be attributed.
 * - `trips.saved_plan_id`: which plan the booking came from. This is what makes
 *   "only the creator books" checkable at all, and it's the anchor the backlog's
 *   "booking facts on a plan entry" item needs to hang an Inquiry off the plan
 *   entry it belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('saved_plan_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('inquiries', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('trip_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropForeignIdFor(User::class);
            $table->dropForeignIdFor(SavedPlan::class);
            $table->dropColumn(['user_id', 'saved_plan_id']);
        });

        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropForeignIdFor(User::class);
            $table->dropColumn('user_id');
        });
    }
};
