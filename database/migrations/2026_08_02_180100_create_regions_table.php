<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Namibia's 14 official political regions — previously only a free-text
 * string (see config('kaia.regions')). Real reference data now, so cities
 * and listings can have a proper region_id instead of a hand-typed name.
 *
 * deploy.sh only runs `migrate --force`, never `db:seed` — so the 14 rows
 * are seeded directly here (see 2026_07_28_200000_seed_region_coordinates.php
 * for the established precedent) rather than relying on RegionSeeder, which
 * exists only for local `migrate:fresh --seed` convenience.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        $names = [
            'Khomas', 'Erongo', 'Hardap', 'Kunene', 'Otjozondjupa', 'Karas',
            'Ohangwena', 'Omusati', 'Oshana', 'Oshikoto', 'Kavango East',
            'Kavango West', 'Zambezi', 'Omaheke',
        ];

        $now = now();

        DB::table('regions')->insert(array_map(fn (string $name) => [
            'name' => $name,
            'slug' => Str::slug($name),
            'created_at' => $now,
            'updated_at' => $now,
        ], $names));
    }

    public function down(): void
    {
        Schema::dropIfExists('regions');
    }
};
