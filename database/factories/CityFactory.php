<?php

namespace Database\Factories;

use App\Enums\SettlementType;
use App\Models\City;
use App\Models\Region;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->city();

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
