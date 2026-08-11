<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a desk said when it put more people in a room than it sleeps.
 *
 * Capacity is a rule on the website and a *question* at a desk: a cot goes in
 * the room, a teenager takes the sofa, a family of five takes a chalet for
 * four for one night. A receptionist told "no" by software they cannot argue
 * with writes the booking on paper instead, and then the property's own system
 * does not know about the guest at all.
 *
 * So the desk is warned, and the answer it gives is kept. Recorded the way a
 * price override is — a column on the stay rather than a line in a running
 * note — for the same reason: it is a property of this booking that somebody
 * later has to be able to *ask about*. "Which rooms need an extra bed tonight"
 * is a question with an answer, and free text in a notes field is not one.
 *
 * Nullable, and null on every booking that fits, which is nearly all of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('over_capacity_note')->nullable()->after('price_overridden_at');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('over_capacity_note');
        });
    }
};
