<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the difference between what the calendar priced a stay at and what
 * the lodge decided to charge.
 *
 * A front desk overrides a price constantly — a regular guest, a tour
 * operator rate, an apology for a broken air conditioner. Letting them type
 * over the total is table stakes; letting them type over it *silently* means
 * nobody can ever answer why last month's revenue does not match the rate
 * calendar.
 *
 * So `total_amount` stays what will actually be charged, and `quoted_amount`
 * keeps what the calendar said at the moment of writing. An override is
 * therefore not a flag but a discrepancy between two recorded numbers, which
 * survives someone later editing either one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->decimal('quoted_amount', 12, 2)->nullable()->after('total_amount');
            $table->string('price_override_reason')->nullable()->after('quoted_amount');
            $table->foreignId('price_overridden_by')->nullable()->after('price_override_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('price_overridden_at')->nullable()->after('price_overridden_by');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_overridden_by');
            $table->dropColumn(['quoted_amount', 'price_override_reason', 'price_overridden_at']);
        });
    }
};
