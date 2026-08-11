<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a partner as a sandbox built for prospection rather than a real
 * business we owe anything to.
 *
 * The flag is not decoration. It is what `InventoryWriter::purgeProperty()`
 * checks before it is willing to hard-delete a property's whole book, which
 * is how `booking:demo-tenant` can rebuild a tenant a prospect has made a
 * mess of without that same code path ever being able to erase a real lodge.
 *
 * `demo_source_listing_id` records which real listing the tenant was copied
 * from, so a rebuild knows what to copy again. It is a **read-only** pointer:
 * nothing in the demo path ever writes to the listing it names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index();
            $table->foreignId('demo_source_listing_id')->nullable()->constrained('listings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropConstrainedForeignId('demo_source_listing_id');
            $table->dropColumn('is_demo');
        });
    }
};
