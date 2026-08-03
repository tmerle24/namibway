<?php

namespace Database\Seeders;

use App\Models\Region;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class RegionSeeder extends Seeder
{
    /**
     * Namibia's 14 official political regions — kept in sync with
     * config('kaia.regions'), which the AI itinerary engine uses to
     * validate/canonicalize the region name a day's location resolves to.
     */
    private const NAMES = [
        'Khomas', 'Erongo', 'Hardap', 'Kunene', 'Otjozondjupa', 'Karas',
        'Ohangwena', 'Omusati', 'Oshana', 'Oshikoto', 'Kavango East',
        'Kavango West', 'Zambezi', 'Omaheke',
    ];

    public function run(): void
    {
        foreach (self::NAMES as $name) {
            Region::firstOrCreate(['slug' => Str::slug($name)], ['name' => $name]);
        }
    }
}
