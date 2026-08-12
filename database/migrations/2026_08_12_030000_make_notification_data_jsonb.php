<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs a `notifications.data` column that was created as `text`.
 *
 * The migration beside this one has been corrected, which is enough for any
 * database built from scratch afterwards — but production had already run the
 * wrong version, and the symptom is not subtle: Filament's bell queries
 * `data->>'format'`, Postgres has no such operator on text, and every page of
 * the admin panel answers 500.
 *
 * Separate rather than edited in place, because editing a migration that has
 * already run somewhere is a change that only helps the machines that have not
 * run it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Postgres only. SQLite has no distinct json type and MySQL's json
        // accepts the same operators either way, so there is nothing to repair.
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('notifications')) {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE jsonb USING data::jsonb');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('notifications')) {
            return;
        }

        DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
    }
};
