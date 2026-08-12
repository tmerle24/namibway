<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The credit side of a stay. Until now there was none: a reservation carried
 * `total_amount`, `charges_amount` and `discount_amount` — the whole debit
 * side — and nothing anywhere said whether a guest had paid.
 *
 * ## One row per money movement, and never anything else
 *
 * Cash handed over the desk, an EFT that lands three days later, a card at the
 * terminal, a gateway capture: same row, same effect on the balance. That
 * sameness is the point — see PAYMENTS.md §1. *Recording* a payment is
 * identical in every settlement model; only *collecting* differs, and that is
 * what `collected_by` carries.
 *
 * A refund is a **negative payment on the same folio**, not a second table and
 * not a deletion. "We refunded N$ 2,400 on 14 September" is a fact somebody
 * defends a year later, and a deleted row defends nothing.
 *
 * Nothing here is ever edited. A payment entered wrongly is reversed by a
 * negative row that points at it through `reverses_payment_id`, and both rows
 * stay. The single exception is `status`, because `recorded → cleared` is a
 * fact arriving late rather than a fact being rewritten.
 *
 * ## Three currency facts, not one
 *
 * The folio is in NAD (config/currencies.php). A guest may hand over EUR. What
 * was owed, what was actually received and in which currency, and the rate
 * used are three separate facts, and all three are stored — a payment
 * converted at display time is a payment that will not reconcile. Where the
 * guest paid in the folio's own currency the received_* columns stay null,
 * which keeps the common case free of redundant copies.
 *
 * ## Recorded is not cleared
 *
 * An EFT a partner says has arrived and a gateway capture the gateway has
 * confirmed are not the same certainty. `recorded` is somebody's word,
 * `cleared` is the money's. Both count against the balance — a desk that has
 * been handed cash is not waiting for anything — but a property chasing
 * money needs to see which is which.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            // The reservation *is* the folio — deliberately no folios table.
            // See PAYMENTS_BUILD.md slice 1. Split folios (room account vs
            // extras vs a company account) are a real PMS feature and a known
            // future need; this is where they would attach.
            $table->foreignId('reservation_id')->constrained()->cascadeOnDelete();

            // decimal(12,2) to match reservations.total_amount. Signed: a
            // refund is this same column with a minus in front of it.
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);

            // What the guest actually handed over, when that was not the
            // folio's currency. Null in the ordinary case.
            $table->decimal('received_amount', 12, 2)->nullable();
            $table->string('received_currency', 3)->nullable();

            // The rate that turned the one into the other, kept because the
            // rate on the day is not the rate next month. Eight decimals: a
            // NAD-per-EUR rate needs more than two to round-trip.
            $table->decimal('exchange_rate', 16, 8)->nullable();

            // When the money moved, which is not when the row was written —
            // an EFT is entered on Thursday for a Tuesday transfer.
            $table->timestamp('received_at');

            $table->string('method');
            $table->string('collected_by');
            $table->string('status');

            // The desk's own handle on the money: a receipt number, an EFT
            // reference, the gateway's transaction id.
            $table->string('reference')->nullable();

            $table->foreignId('reverses_payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();

            $table->timestamps();

            // The folio view reads every payment of one stay, oldest first.
            $table->index(['reservation_id', 'received_at']);

            // "What came in this week, and how much of it is still only
            // somebody's word" — the two questions a property asks across
            // stays rather than within one.
            $table->index(['status', 'received_at']);

            // A payment is reversed once. Two reversals of one payment would
            // credit the guest twice, and the constraint is cheaper than the
            // check — the same discipline as reservations.inquiry_id.
            $table->unique('reverses_payment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
