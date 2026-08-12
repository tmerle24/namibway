<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An attempt to take money through a payment provider.
 *
 * Separate from `payments` on purpose, and the line between them is the whole
 * point: **`payments` is money that moved; this is money we asked for.** A
 * guest who mistypes a card three times leaves three rows here and none there,
 * and the folio stays readable as a folio.
 *
 * That is also the answer to what a declined payment produces — nothing in the
 * ledger. `PaymentStatus::Failed` exists for a different case: an EFT somebody
 * recorded as received and the bank later did not honour. Money believed to
 * have moved and money that never started moving are different facts, and
 * folding them together would make "how much came in" a question you cannot
 * ask of the payments table alone.
 *
 * ## The reference is the whole security model
 *
 * `reference` is what appears in the URL a guest opens and in the callback a
 * gateway posts back, so it is a long random string rather than the id. An
 * incrementing integer in a payment link is an invitation to read somebody
 * else's booking by subtracting one.
 *
 * ## Idempotency
 *
 * A gateway will deliver the same callback more than once — that is normal
 * operation, not a fault. `payments.payment_intent_id` is unique, so a second
 * delivery cannot produce a second payment. The constraint is the mechanism,
 * the same way `reservations.inquiry_id` is; the status check in the gateway
 * service is the courtesy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();

            // Ours, not the gateway's: it exists before any gateway has been
            // told about the attempt, and it is what we recognise a callback
            // by. Unguessable, because it is a URL.
            $table->string('reference', 64)->unique();

            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();

            // Which implementation created it. A string rather than an enum
            // column: providers come and go with commercial arrangements, and
            // a row that names a provider we have since dropped still has to
            // be readable.
            $table->string('provider');

            $table->string('purpose');
            $table->string('status');

            // What the folio is owed, in the folio's currency.
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);

            // What the gateway is actually asked for. Not always the same:
            // no provider we can reach settles NAD, so a Namibian folio is
            // charged in ZAR at the Common Monetary Area's 1:1 peg. Stored
            // rather than recomputed, because a peg is a policy and policies
            // end.
            $table->decimal('charge_amount', 12, 2);
            $table->string('charge_currency', 3);
            $table->decimal('exchange_rate', 16, 8);

            // The gateway's own id for the attempt, once it has one.
            $table->string('provider_reference')->nullable();

            // Whatever the provider needs to keep — an access code, the
            // authorisation URL, the last callback payload. Deliberately
            // untyped: it is the provider's business and nothing above the
            // interface reads it.
            $table->jsonb('meta')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['reservation_id', 'status']);
            $table->index(['provider', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
