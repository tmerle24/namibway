<?php

namespace Database\Factories;

use App\Enums\SupplyService;
use App\Models\SupplyPoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupplyPoint>
 */
class SupplyPointFactory extends Factory
{
    protected $model = SupplyPoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->city().' Filling Station';

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'services' => [SupplyService::Fuel],
            'lat' => $this->faker->latitude(-28, -17),
            'lng' => $this->faker->longitude(12, 25),
            'is_published' => true,
        ];
    }

    public function groceries(): static
    {
        return $this->state(fn (): array => ['services' => [SupplyService::Groceries]]);
    }
}
