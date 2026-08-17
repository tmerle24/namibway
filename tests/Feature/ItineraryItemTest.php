<?php

namespace Tests\Feature;

use App\Enums\InquiryStatus;
use App\Enums\ListingType;
use App\Models\Inquiry;
use App\Models\ItineraryItem;
use App\Models\Listing;
use App\Models\SavedPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The data layer behind bookable plan items.
 *
 * An `ItineraryItem` row is created at the moment booking requests are sent —
 * JSON stays for planning, rows arrive when a plan item becomes real. What is
 * asserted here: the row exists, carries the right dates and the inquiry it
 * produced, and makes the booking status visible on the plan page.
 */
class ItineraryItemTest extends TestCase
{
    use RefreshDatabase;

    private SavedPlan $plan;

    private Listing $lodge;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->lodge = Listing::factory()->create([
            'type' => ListingType::Accommodation,
        ]);

        $this->plan = SavedPlan::create([
            'title' => 'Namibia in ten days',
            'plan_json' => ['trip_summary' => 'Namibia in ten days', 'variants' => []],
        ]);
    }

    /** @return array<string, mixed> */
    private function bookingPayload(string $checkIn = '2027-03-01', string $checkOut = '2027-03-04'): array
    {
        return [
            'name' => 'Anna Müller',
            'email' => 'anna@example.com',
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => 2,
            'variant_name' => 'Classic North',
            'plan' => $this->plan->plan_json,
            'variant_days' => [
                [
                    'date' => '1 Mar 2027',
                    'accommodation' => ['id' => $this->lodge->id],
                ],
                [
                    'date' => '2 Mar 2027',
                    'accommodation' => ['id' => $this->lodge->id],
                ],
                [
                    'date' => '3 Mar 2027',
                    'accommodation' => ['id' => $this->lodge->id],
                ],
            ],
            'plan_token' => $this->plan->token,
        ];
    }

    public function test_booking_creates_an_itinerary_item_for_each_accommodation(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('trips.store'), $this->bookingPayload())
            ->assertOk();

        $this->assertSame(1, ItineraryItem::count());

        $item = ItineraryItem::first();

        $this->assertSame($this->plan->id, $item->saved_plan_id);
        $this->assertSame($this->lodge->id, $item->listing_id);
        $this->assertSame(ListingType::Accommodation, $item->kind);
    }

    public function test_itinerary_item_date_range_spans_the_accommodation_stay(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('trips.store'), $this->bookingPayload())
            ->assertOk();

        $item = ItineraryItem::first();

        $this->assertSame('2027-03-01', $item->date?->toDateString());
        // check-out is the morning after the last night
        $this->assertSame('2027-03-04', $item->date_to?->toDateString());
    }

    public function test_itinerary_item_is_linked_to_the_created_inquiry(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('trips.store'), $this->bookingPayload())
            ->assertOk();

        $item = ItineraryItem::first();
        $inquiry = Inquiry::first();

        $this->assertNotNull($item->inquiry_id);
        $this->assertSame($inquiry->id, $item->inquiry_id);
        $this->assertSame($item->id, $inquiry->itineraryItem?->id);
    }

    public function test_booking_status_is_seeded_into_the_plan_show_response(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('trips.store'), $this->bookingPayload())
            ->assertOk();

        // Mark the inquiry confirmed so the status flows through.
        Inquiry::first()->update(['status' => InquiryStatus::Confirmed]);

        $lodgeId = $this->lodge->id;

        $this->actingAs($this->user)
            ->get(route('trip.show', $this->plan->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('bookings', 1)
                ->where('bookings.0.listing_id', $lodgeId)
                ->where('bookings.0.inquiry_status', 'confirmed')
            );
    }

    public function test_two_separate_accommodations_each_get_their_own_item(): void
    {
        $second = Listing::factory()->create(['type' => ListingType::Accommodation]);

        $payload = $this->bookingPayload();
        $payload['variant_days'][1]['accommodation'] = ['id' => $second->id];
        $payload['variant_days'][2]['accommodation'] = ['id' => $second->id];

        $this->actingAs($this->user)
            ->postJson(route('trips.store'), $payload)
            ->assertOk();

        $this->assertSame(2, ItineraryItem::count());

        $lodgeItem = ItineraryItem::where('listing_id', $this->lodge->id)->first();
        $secondItem = ItineraryItem::where('listing_id', $second->id)->first();

        $this->assertNotNull($lodgeItem);
        $this->assertNotNull($secondItem);
        // Each item has its own inquiry.
        $this->assertNotSame($lodgeItem->inquiry_id, $secondItem->inquiry_id);
        // The lodge only covers the first night.
        $this->assertSame('2027-03-01', $lodgeItem->date?->toDateString());
        $this->assertSame('2027-03-02', $lodgeItem->date_to?->toDateString());
    }
}
