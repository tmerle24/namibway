<?php

namespace Database\Factories;

use App\Enums\AttractionType;
use App\Models\Attraction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Attraction>
 */
class AttractionFactory extends Factory
{
    protected $model = Attraction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->streetName().' Site';

        return [
            'name' => ['en' => $name],
            'slug' => Str::slug($name),
            'type' => $this->faker->randomElement(AttractionType::cases()),
            'lat' => $this->faker->latitude(-28, -17),
            'lng' => $this->faker->longitude(12, 25),
            'is_published' => true,
        ];
    }
}
