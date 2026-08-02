<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The "regions" table has always actually held tourist destinations/explore
 * cards (Windhoek, Etosha, Sossusvlei...), tagged with which political
 * region they belong to via listing_region. Renaming it to make room for a
 * real "regions" table holding Namibia's 14 political regions themselves —
 * see 2026_08_02_180100_create_regions_table.php.
 *
 * Postgres's `ALTER TABLE ... RENAME TO` (what Schema::rename() issues)
 * renames the table but leaves its indexes/constraints under their old
 * auto-generated names (regions_pkey, regions_slug_unique) — those have to
 * be renamed explicitly, otherwise the new "regions" table created next
 * collides with them when it declares its own id/slug unique constraint.
 * SQLite (used in tests) doesn't name indexes this way, so this is
 * Postgres-only — skipped on other drivers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('regions', 'destinations');

        Schema::table('destinations', function (Blueprint $table) {
            $table->renameColumn('listing_region', 'region_name');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER INDEX regions_pkey RENAME TO destinations_pkey');
            DB::statement('ALTER INDEX regions_slug_unique RENAME TO destinations_slug_unique');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER INDEX destinations_pkey RENAME TO regions_pkey');
            DB::statement('ALTER INDEX destinations_slug_unique RENAME TO regions_slug_unique');
        }

        Schema::table('destinations', function (Blueprint $table) {
            $table->renameColumn('region_name', 'listing_region');
        });

        Schema::rename('destinations', 'regions');
    }
};
