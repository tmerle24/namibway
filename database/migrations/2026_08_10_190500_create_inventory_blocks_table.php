<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Units taken off sale without a guest booking them: maintenance, owner use,
 * a group hold that isn't firm yet. A block consumes inventory exactly like
 * a reservation does, but through its own counter (units_blocked) so an
 * occupancy view can tell "sold out" from "we took rooms off sale" — which
 * is the entire reason a block is not just a fake booking.
 *
 * The dates are named first_night / last_night, both inclusive, and that is
 * deliberately different from a reservation's half-open check_in/check_out.
 * A stay has an arrival and a departure day; a block only ever has nights,
 * and naming the columns after nights removes the off-by-one that mixing the
 * two conventions under one pair of names would invite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_type_id')->constrained()->restrictOnDelete();

            $table->string('reason');
            $table->unsignedSmallInteger('units');
            $table->date('first_night');
            $table->date('last_night');

            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();

            $table->timestamps();

            $table->index(['room_type_id', 'first_night', 'last_night']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_blocks');
    }
};
