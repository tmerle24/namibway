<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $this->call(RegionSeeder::class);
        $this->call(CitySeeder::class);
        $this->call(PartnerSeeder::class);
        $this->call(ListingSeeder::class);
        $this->call(DestinationSeeder::class);
        $this->call(RouteTemplateSeeder::class);
    }
}
