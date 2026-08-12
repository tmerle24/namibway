<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The platform's own commission and deposit rates — one row, edited in the
 * admin panel.
 *
 * A table rather than `config/`, and that is the whole point: these numbers
 * are commercial terms, not configuration. The team changes them when a
 * conversation with a partner changes, and needing a deploy for that means
 * they get changed in a hurry by whoever can deploy, or not changed at all.
 * `config/payments.php` stays as the value this row is seeded from and the
 * fallback if it has not been created yet.
 *
 * The same shape as `message_settings`, deliberately — a second pattern for
 * "one row of settings the team edits" would be one more thing to learn.
 *
 * `minimum_deposit_rate` is nullable and null means "the commission rate".
 * Storing the meaning rather than a copied number keeps the floor following
 * the commission when that changes, which is what makes the cheapest
 * arrangement — deposit exactly equal to commission, nothing owed in either
 * direction — the natural landing spot rather than a coincidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_settings', function (Blueprint $table) {
            $table->id();

            // decimal(6,3) rather than (5,2): commission is negotiated in
            // fractions of a percent often enough that 4.75 % has to be
            // expressible, and the extra digit costs nothing.
            $table->decimal('commission_rate', 6, 3);
            $table->decimal('deposit_rate', 6, 3);
            $table->decimal('minimum_deposit_rate', 6, 3)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_settings');
    }
};
