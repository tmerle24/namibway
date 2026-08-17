<?php

namespace Database\Factories;

use App\Enums\ListingType;
use App\Models\City;
use App\Models\Listing;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Listing>
 */
class ListingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();

        return [
            'partner_id' => Partner::factory(),
            'type' => fake()->randomElement(ListingType::cases()),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1, 100000),
            'description' => fake()->paragraphs(3, true),
            'city_id' => City::factory(),
            'latitude' => fake()->latitude(-28, -17),
            'longitude' => fake()->longitude(11, 25),
            'price_from' => fake()->randomFloat(2, 20, 800),
            'price_currency' => 'NAD',
            'is_featured' => fake()->boolean(20),
            'is_published' => fake()->boolean(80),
        ];
    }
}
