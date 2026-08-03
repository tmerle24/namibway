<?php

namespace Tests\Feature\Api;

use App\Enums\ConnectorType;
use App\Models\ApiClient;
use App\Models\City;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Region;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ListingApiTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(ApiClient $client): string
    {
        return $client->createToken('test')->plainTextToken;
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/v1/listings')->assertUnauthorized();
    }

    public function test_inactive_client_is_rejected(): void
    {
        $client = ApiClient::create(['name' => 'Blocked Consumer', 'is_active' => false]);

        $this->withToken($this->tokenFor($client))
            ->getJson('/api/v1/listings')
            ->assertForbidden();
    }

    public function test_index_only_returns_published_listings_and_respects_filters(): void
    {
        $client = ApiClient::create(['name' => 'OTA One']);

        $erongoCity = City::factory()->create(['region_id' => Region::where('name', 'Erongo')->value('id')]);
        $khomasCity = City::factory()->create(['region_id' => Region::where('name', 'Khomas')->value('id')]);

        Listing::factory()->create(['is_published' => true, 'city_id' => $erongoCity->id, 'name' => 'Published Lodge']);
        Listing::factory()->create(['is_published' => false, 'city_id' => $erongoCity->id, 'name' => 'Draft Lodge']);
        Listing::factory()->create(['is_published' => true, 'city_id' => $khomasCity->id, 'name' => 'Other Region Lodge']);

        $response = $this->withToken($this->tokenFor($client))
            ->getJson('/api/v1/listings?region=Erongo')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Published Lodge'));
        $this->assertFalse($names->contains('Draft Lodge'));
        $this->assertFalse($names->contains('Other Region Lodge'));
    }

    public function test_index_filters_by_city_distinctly_from_region(): void
    {
        $client = ApiClient::create(['name' => 'OTA Two']);

        $erongoRegionId = Region::where('name', 'Erongo')->value('id');
        $swakopmund = City::factory()->create(['region_id' => $erongoRegionId, 'name' => 'Swakopmund']);
        $walvisBay = City::factory()->create(['region_id' => $erongoRegionId, 'name' => 'Walvis Bay']);

        Listing::factory()->create(['is_published' => true, 'city_id' => $swakopmund->id, 'name' => 'Swakopmund Lodge']);
        Listing::factory()->create(['is_published' => true, 'city_id' => $walvisBay->id, 'name' => 'Walvis Bay Lodge']);

        // Same region for both — only the city filter should tell them apart.
        $response = $this->withToken($this->tokenFor($client))
            ->getJson('/api/v1/listings?city=Swakopmund')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name');

        $this->assertTrue($names->contains('Swakopmund Lodge'));
        $this->assertFalse($names->contains('Walvis Bay Lodge'));
    }

    public function test_show_404s_for_unpublished_listing(): void
    {
        $client = ApiClient::create(['name' => 'OTA One']);
        $listing = Listing::factory()->create(['is_published' => false]);

        $this->withToken($this->tokenFor($client))
            ->getJson("/api/v1/listings/{$listing->slug}")
            ->assertNotFound();
    }

    public function test_availability_returns_inquiry_mode_for_manual_partner(): void
    {
        $client = ApiClient::create(['name' => 'OTA One']);
        $partner = Partner::create(['name' => 'Manual Lodge', 'connector_type' => ConnectorType::Manual->value]);
        $listing = Listing::factory()->create(['is_published' => true, 'partner_id' => $partner->id]);

        $response = $this->withToken($this->tokenFor($client))
            ->getJson("/api/v1/listings/{$listing->slug}/availability?check_in=".now()->addDays(10)->toDateString().'&check_out='.now()->addDays(14)->toDateString())
            ->assertOk();

        $response->assertJson([
            'live_availability' => false,
            'booking_mode' => 'inquiry',
        ]);
    }

    public function test_availability_returns_inquiry_mode_when_listing_has_no_partner(): void
    {
        $client = ApiClient::create(['name' => 'OTA One']);
        $listing = Listing::factory()->create(['is_published' => true, 'partner_id' => null]);

        $response = $this->withToken($this->tokenFor($client))
            ->getJson("/api/v1/listings/{$listing->slug}/availability?check_in=".now()->addDays(10)->toDateString().'&check_out='.now()->addDays(14)->toDateString())
            ->assertOk();

        $response->assertJson([
            'live_availability' => false,
            'booking_mode' => 'inquiry',
        ]);
    }

    public function test_availability_proxies_live_connector_response(): void
    {
        Http::fake([
            'api.resrequest.com/*' => Http::response([
                'room_types' => [
                    [
                        'code' => 'std',
                        'name' => 'Standard Room',
                        'available' => 2,
                        'rate_per_night' => 1500,
                        'currency' => 'NAD',
                        'total_rate' => 6000,
                    ],
                ],
            ], 200),
        ]);

        $client = ApiClient::create(['name' => 'OTA One']);
        $partner = Partner::create([
            'name' => 'Connected Lodge',
            'connector_type' => ConnectorType::ResConnect->value,
            'connector_config' => ['api_key' => 'test-key'],
            'connector_verified_at' => now(),
        ]);
        $listing = Listing::factory()->create([
            'is_published' => true,
            'partner_id' => $partner->id,
            'connector_property_code' => 'PROP1',
        ]);

        $response = $this->withToken($this->tokenFor($client))
            ->getJson("/api/v1/listings/{$listing->slug}/availability?check_in=".now()->addDays(10)->toDateString().'&check_out='.now()->addDays(14)->toDateString())
            ->assertOk();

        $response->assertJson([
            'live_availability' => true,
            'booking_mode' => 'connector',
            'connector' => 'resconnect',
            'available' => true,
        ]);

        $this->assertSame('std', $response->json('room_types.0.code'));
    }
}
