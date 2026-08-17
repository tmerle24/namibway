<?php

namespace Database\Factories;

use App\Enums\BusinessType;
use App\Enums\SiteStatus;
use App\Models\Partner;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();
        $slug = Str::slug($name).'-'.fake()->unique()->numberBetween(1, 100000);

        return [
            'partner_id' => Partner::factory(),
            'business_type' => fake()->randomElement(BusinessType::cases()),
            'name' => $name,
            'slug' => $slug,
            'host' => null,
            'status' => SiteStatus::Draft,
            'accent' => 'copper',
            'default_locale' => 'en',
            'contact_email' => fake()->companyEmail(),
            'contact_phone' => '+264 61 123 4567',
        ];
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => SiteStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function onHost(string $host): static
    {
        return $this->state(fn () => ['host' => $host]);
    }
}
