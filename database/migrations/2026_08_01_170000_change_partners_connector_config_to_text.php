<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * partners.connector_config is cast as `encrypted:array` on the model, which
 * serializes to an opaque encrypted string — not valid JSON. Postgres's
 * `json` column type validates syntax on write, so no encrypted value can
 * ever be stored in it; every save silently fails with "invalid input
 * syntax for type json". This was broken from the original
 * add_connector_fields_to_partners_table migration onward — a plain text
 * column is the correct type for an encrypted-string cast.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE partners ALTER COLUMN connector_config TYPE text USING connector_config::text');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE partners ALTER COLUMN connector_config TYPE json USING connector_config::json');
    }
};
