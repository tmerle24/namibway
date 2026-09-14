<?php

namespace Tests\Feature;

use App\Enums\ListingType;
use App\Enums\VehicleCategory;
use App\Models\Inquiry;
use App\Models\ItineraryItem;
use App\Models\Listing;
use App\Models\SavedPlan;
use App\Models\User;
use App\Services\Kaia\ItineraryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A guided trip is one product: the operator drives it and quotes the whole
 * route. So it is one request to him — not one to every lodge in the plan,
 * which is the flooding the request mechanic exists to prevent.
 */
class GuidedTripBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Listing $lodge;

    private Listing $tour;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->lodge = Listing::factory()->create(['type' => ListingType::Accommodation, 'name' => 'Test Lodge']);
        $this->tour = Listing::factory()->create([
            'type' => ListingType::Vehicle,
            'vehicle_category' => VehicleCategory::GuidedTour,
            'name' => 'Grand Namibia Safari',
        ]);
    }

    private function plan(string $vehicleType, ?int $vehicleId): SavedPlan
    {
        return SavedPlan::create([
            'title' => 'Namibia',
            'plan_json' => [
                'trip_summary' => 'Namibia',
                'trip_params' => ['vehicle_type' => $vehicleType],
                'variants' => [[
                    'name' => 'Guided',
                    'vehicle' => $vehicleId === null ? null : ['id' => $vehicleId, 'name' => 'Grand Namibia Safari'],
                    'days' => [],
                ]],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(SavedPlan $plan): array
    {
        return [
            'name' => 'Anna Müller',
            'email' => 'anna@example.com',
            'check_in' => '2027-03-01',
            'check_out' => '2027-03-04',
            'adults' => 2,
            'variant_name' => 'Guided',
            'plan' => $plan->plan_json,
            'variant_days' => [
                ['day' => 1, 'date' => '1 Mar 2027', 'location' => 'Windhoek', 'accommodation' => ['id' => $this->lodge->id, 'name' => 'Test Lodge'], 'activities' => [['name' => 'Game drive']]],
                ['day' => 2, 'date' => '2 Mar 2027', 'location' => 'Sossusvlei', 'accommodation' => ['id' => $this->lodge->id, 'name' => 'Test Lodge']],
                ['day' => 3, 'date' => '3 Mar 2027', 'location' => 'Swakopmund', 'accommodation' => ['id' => $this->lodge->id, 'name' => 'Test Lodge']],
            ],
            'plan_token' => $plan->token,
        ];
    }

    public function test_a_guided_trip_sends_one_request_to_the_operator(): void
    {
        $plan = $this->plan(ItineraryService::GUIDED, $this->tour->id);

        $this->actingAs($this->user)
            ->postJson(route('trips.store'), $this->payload($plan))
            ->assertOk()
            ->assertJson(['inquiry_count' => 1]);

        $inquiry = Inquiry::sole();

        $this->assertSame($this->tour->id, $inquiry->listing_id);
        $this->assertSame('2027-03-01', $inquiry->check_in?->toDateString());
        $this->assertSame('2027-03-04', $inquiry->check_out?->toDateString());

        // The route rides along, so the operator can quote without asking.
        $this->assertStringContainsString('Day 1', (string) $inquiry->message);
        $this->assertStringContainsString('Test Lodge', (string) $inquiry->message);
        $this->assertStringContainsString('Game drive', (string) $inquiry->message);
        $this->assertStringContainsString('not a booking', (string) $inquiry->message);

        // And no lodge was asked anything.
        $this->assertSame(0, Inquiry::where('listing_id', $this->lodge->id)->count());
        $this->assertSame($this->tour->id, ItineraryItem::sole()->listing_id);
    }

    public function test_a_self_drive_trip_still_asks_every_lodge(): void
    {
        $plan = $this->plan('car', null);

        $this->actingAs($this->user)
            ->postJson(route('trips.store'), $this->payload($plan))
            ->assertOk()
            ->assertJson(['inquiry_count' => 1]);

        $this->assertSame($this->lodge->id, Inquiry::sole()->listing_id);
    }

    /**
     * Both halves have to agree. A plan that says guided but points at a hire
     * car is a plan somebody edited — the lodges are then booked the ordinary
     * way rather than an operator being asked who was never chosen.
     */
    public function test_guided_without_a_guided_tour_books_the_lodges(): void
    {
        $car = Listing::factory()->create([
            'type' => ListingType::Vehicle,
            'vehicle_category' => VehicleCategory::SelfDrive,
        ]);

        $plan = $this->plan(ItineraryService::GUIDED, $car->id);

        $this->actingAs($this->user)
            ->postJson(route('trips.store'), $this->payload($plan))
            ->assertOk();

        $this->assertSame($this->lodge->id, Inquiry::sole()->listing_id);
    }
}
