<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which attempt a payment came from, and the constraint that makes a repeated
 * callback harmless.
 *
 * A gateway delivering the same webhook twice is normal operation. The unique
 * index is what makes the second delivery a no-op rather than a second credit
 * on the guest's folio — exactly the discipline `reservations.inquiry_id`
 * already uses, and for the same reason: a PHP check races with itself, a
 * unique index does not.
 *
 * Nullable, because most payments never touch a provider. Cash at the desk is
 * the common case and always will be at a Namibian lodge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('payment_intent_id')
                ->nullable()
                ->after('reverses_payment_id')
                ->constrained()
                ->nullOnDelete();

            $table->unique('payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['payment_intent_id']);
            $table->dropConstrainedForeignId('payment_intent_id');
        });
    }
};
