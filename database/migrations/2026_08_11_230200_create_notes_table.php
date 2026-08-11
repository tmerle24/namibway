<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What staff wrote about a customer or a stay, with who wrote it and when.
 *
 * Polymorphic because the two subjects behave identically — "asked for a quiet
 * room, twice now", "paid the balance in cash on arrival" — and because the
 * next subjects (an invoice, a partner, a vehicle when the system reaches car
 * rental) will want the same thing rather than a third column somewhere.
 *
 * ## Why this is not the `reservations.notes` column
 *
 * That column is part of the booking: what the person making it said at the
 * time, frozen with the stay like the price is. This table is the running log
 * that starts afterwards, and its entries have an author and a timestamp
 * precisely because a note nobody can attribute is one nobody trusts. Both are
 * kept; neither replaces the other.
 *
 * `author_name` is written beside `user_id` on purpose. A staff account that
 * is later deleted must not turn last season's notes anonymous — the same
 * freeze-the-fact rule the reservation applies to its price and its rate plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->morphs('notable');

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name');

            $table->text('body');

            $table->timestamps();

            // The log is read newest first, per subject.
            $table->index(['notable_type', 'notable_id', 'created_at'], 'notes_subject_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};
