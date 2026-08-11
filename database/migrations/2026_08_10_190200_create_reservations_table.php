<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A stay at a property — separate from Inquiry, which is a traveller's
 * request. A walk-in has a stay and never had a request; a declined request
 * never becomes a stay. CLAUDE.md ("How a confirmed Inquiry becomes a
 * Reservation") holds the full reasoning and the shape of the bridge.
 *
 * `inquiry_id` is that bridge, and nothing writes it in this slice. It is
 * unique on purpose: promotion has to be idempotent, so a re-confirm, a
 * retried job or a partner clicking the signed link twice cannot produce a
 * second stay. That constraint is the mechanism — do not replace it with a
 * check in PHP.
 *
 * check_in / check_out are half-open (check_out is a departure day, not a
 * night) and are derived from the reservation's unit lines by
 * InventoryWriter. They exist so an arrivals list is one indexed query
 * rather than a join and an aggregate; nothing else may write them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('listing_id')->constrained()->restrictOnDelete();
            $table->foreignId('inquiry_id')->nullable()->unique()->constrained()->nullOnDelete();

            $table->string('status')->index();
            $table->string('source');

            $table->string('guest_name');
            $table->string('guest_email')->nullable();
            $table->string('guest_phone')->nullable();

            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('adults')->default(1);
            $table->unsignedSmallInteger('children')->default(0);

            $table->decimal('total_amount', 12, 2)->nullable();
            $table->char('currency', 3);

            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->timestamps();

            // The two questions a front desk asks: who arrives today, and who
            // is currently in house.
            $table->index(['listing_id', 'check_in']);
            $table->index(['listing_id', 'status', 'check_out']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
