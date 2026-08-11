<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which rate plan a room line was sold under.
 *
 * Recorded on the line rather than the reservation because one booking can
 * genuinely mix them — a family taking a resident rate for themselves and a
 * public rate for a visiting friend is a real front-desk case, and a column on
 * the header could not express it.
 *
 * Nullable, and it stays nullable: every stay written before rate plans
 * existed was sold under whatever the room type charged, and inventing a plan
 * for it retrospectively would be a claim about a booking nobody made under
 * one. `nullOnDelete` for the same reason — deleting a plan must not take the
 * history of what was sold with it.
 *
 * This changes nothing about money. What a stay costs is already frozen in
 * reservation_nights and is never recomputed; this column says which product
 * produced those numbers, not what they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->foreignId('rate_plan_id')->nullable()->after('room_type_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('reservation_units', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rate_plan_id');
        });
    }
};
