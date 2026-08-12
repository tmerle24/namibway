<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The zero-deposit unlock, and the moment commission was earned.
 *
 * ## Why zero is a permission and not a number
 *
 * A partner sets their own deposit. Zero is not simply the smallest value in
 * that range: it is the agency model, where we collect nothing and have to
 * *invoice* the partner for commission afterwards — with payment terms, a
 * dunning process, and the question of what we do about a partner who does not
 * pay, for which our only real lever is `booking_enabled`. That moves cost and
 * collection risk onto us, and it is therefore not the partner's decision
 * alone. They still choose their deposit; we choose whether zero is in their
 * range. See PAYMENTS.md § 2.
 *
 * Note there is deliberately **no `settlement_model` column**. The model is
 * derived from the deposit share (App\Enums\SettlementModel) — storing it as
 * well would make it possible to configure a partner into "merchant model, we
 * collect nothing", which is not a thing. One number, three behaviours.
 *
 * ## Why "earned" is a timestamp and not a flag
 *
 * Because the answer to "what did we earn in March" has to be stable, and a
 * boolean cannot say *when*. The amount is already frozen on the row; this
 * says from which moment it counts, and nulling it is how a cancellation takes
 * it back — the amount stays, so the record of what the booking would have
 * earned is not lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('allow_zero_deposit')->default(false)->after('deposit_rate');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('commission_earned_at')->nullable()->after('commission_amount');
        });

        Schema::table('reservations', function (Blueprint $table) {
            // "What did we earn between these dates" is the one question this
            // column exists to answer, so it is the one it is indexed for.
            $table->index('commission_earned_at');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('allow_zero_deposit');
        });

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropIndex(['commission_earned_at']);
            $table->dropColumn('commission_earned_at');
        });
    }
};
