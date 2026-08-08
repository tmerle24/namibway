<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Until now a plan had exactly one token, and it was the same one the creator
 * edits with — so "share this plan" handed over full write access, and there
 * was no way to show someone a plan without letting them change it.
 *
 * `share_token` is the read-only counterpart: it resolves to the same plan but
 * is rejected by KaiaController::updatePlan. The existing `token` keeps its
 * meaning (edit access), so links already in circulation behave exactly as
 * they did — they are not silently downgraded, and no creator loses access to
 * their own plan. What changes is that the Share button now hands out the
 * read-only one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saved_plans', function (Blueprint $table) {
            $table->string('share_token', 16)->nullable()->unique()->after('token');
        });

        // Backfill existing rows so every plan is shareable read-only, not just
        // ones created from here on. Chunked rather than loaded at once — this
        // table grows with every anonymous planning session.
        DB::table('saved_plans')->whereNull('share_token')->orderBy('id')
            ->chunkById(500, function ($plans) {
                foreach ($plans as $plan) {
                    do {
                        $token = Str::random(12);
                    } while (DB::table('saved_plans')->where('share_token', $token)->exists());

                    DB::table('saved_plans')->where('id', $plan->id)->update(['share_token' => $token]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('saved_plans', function (Blueprint $table) {
            $table->dropColumn('share_token');
        });
    }
};
