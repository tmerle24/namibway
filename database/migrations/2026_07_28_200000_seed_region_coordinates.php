<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, array{lat: float, lng: float}> */
    private const COORDS = [
        'khomas'       => ['lat' => -22.5597, 'lng' => 17.0832],
        'erongo'       => ['lat' => -22.0000, 'lng' => 14.9000],
        'hardap'       => ['lat' => -24.5000, 'lng' => 16.5000],
        'kunene'       => ['lat' => -19.5800, 'lng' => 13.9200],
        'etosha'       => ['lat' => -18.8550, 'lng' => 16.3290],
        'otjozondjupa' => ['lat' => -20.4600, 'lng' => 17.9200],
        'karas'        => ['lat' => -27.7500, 'lng' => 18.0000],
    ];

    public function up(): void
    {
        foreach (self::COORDS as $slug => $coords) {
            DB::table('regions')
                ->where('slug', $slug)
                ->update(['lat' => $coords['lat'], 'lng' => $coords['lng']]);
        }
    }

    public function down(): void
    {
        DB::table('regions')
            ->whereIn('slug', array_keys(self::COORDS))
            ->update(['lat' => null, 'lng' => null]);
    }
};
