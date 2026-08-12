<?php

namespace Database\Factories;

use App\Enums\SettlementType;
use App\Models\City;
use App\Models\Region;
use Database\Factories\Concerns\UniqueAcrossTheRun;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    use UniqueAcrossTheRun;

    protected $model = City::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // The slug is a unique column and rows committed by the concurrency
        // suites outlive faker's memory of what it handed out — see
        // UniqueAcrossTheRun.
        $name = $this->neverSeenBefore(fake()->city());

        return [
            'region_id' => Region::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'type' => fake()->randomElement(SettlementType::cases()),
            'population' => fake()->optional()->numberBetween(200, 500000),
            'area_km2' => fake()->optional()->randomFloat(1, 5, 5000),
        ];
    }
}
