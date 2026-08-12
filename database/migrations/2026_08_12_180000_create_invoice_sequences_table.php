<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The counter behind gapless invoice numbering.
 *
 * A table exists for this because the two obvious alternatives are both wrong,
 * and wrong in ways that only show up under load or under audit:
 *
 * - **`max(number) + 1`** reads a value that another transaction is already
 *   about to use. Two check-outs at the same desk in the same second produce
 *   the same number, and nobody finds out until a tax inspector has both
 *   documents on the desk.
 * - **A database auto-increment** hands out a number *before* the transaction
 *   commits, and does not give it back if the transaction rolls back. That is
 *   exactly right for a primary key and exactly wrong for an invoice number: a
 *   sequence with a hole in it is what an auditor asks about first, because the
 *   innocent explanation and the fraudulent one look identical.
 *
 * So the number comes from a row that is locked with `SELECT … FOR UPDATE`
 * inside the issuing transaction. A second issuer waits rather than reading a
 * stale value, and a rollback returns the counter along with everything else —
 * no gap, because the number was never really taken.
 *
 * One counter per **series and year**, which is what "gapless per issuing
 * entity and per year" means in practice: the invoices NamibWay issues are a
 * different run of numbers from the ones a property issues to its own guests,
 * and both restart in January.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();

            // Who is counting: 'NW' for us, 'P{partner_id}' for a property.
            // A string rather than a foreign key, because the series is part
            // of the number a human reads and quotes back, and it has to keep
            // meaning the same thing after the partner row it named is gone.
            $table->string('series', 16);
            $table->unsignedSmallInteger('year');

            // The next number to hand out — not the last one used. Off-by-one
            // errors in this column are invisible until they are permanent.
            $table->unsignedInteger('next_number')->default(1);

            $table->timestamps();

            $table->unique(['series', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_sequences');
    }
};
