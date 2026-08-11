<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SitePage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SitePage>
 */
class SitePageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'slug' => '',
            'locale' => 'en',
            'is_home' => true,
            'sort' => 0,
        ];
    }
}
