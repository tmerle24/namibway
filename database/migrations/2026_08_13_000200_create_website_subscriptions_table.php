<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A partner's subscription to the website product.
 *
 * ## Why there is no price here
 *
 * Because the price is not a property of one customer's subscription — it is a
 * property of the offer, and the offer is one price for everybody
 * (`config('sites.subscription')`). A column here would be a per-customer price
 * nobody decided to have, and the first discount typed into it becomes a
 * commitment nobody remembers making.
 *
 * The same goes for anything naming a gateway. There is no payment provider for
 * this market yet, billing runs by invoice, and the state machine has to work
 * with a human deciding "they have paid" — and go on working unchanged when a
 * provider is plugged in behind it. What that provider needs will be its own
 * table, keyed to this one.
 *
 * ## One per partner
 *
 * A partner subscribes, not a listing: the fee buys the business a website, and
 * a partner with two lodges is one customer with two sites. Enforced with a
 * unique index rather than by care, because a second row would make
 * "are they entitled?" a question with two answers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_subscriptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('partner_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('status', 20)->index();

            // The moments, kept separately rather than derived from the status:
            // "when did they ask?" and "when did they become a customer?" are
            // different questions, and a status column answers neither once it
            // has moved on.
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Why it is where it is — an owner's note when ordering, or ours
            // when suspending. Read by a person, never parsed.
            $table->text('note')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_subscriptions');
    }
};
