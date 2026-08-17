<?php

namespace Tests\Feature;

use App\Models\BookableUnit;
use App\Models\BookingSlot;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /availability — one endpoint for every vertical.
 *
 * The endpoint must not need to know which vertical it serves:
 * it reads the unit's own data (slots or not) and the difference is in the
 * response shape, not in the code path. See BOOKING_BEYOND_ROOMS.md §7.1.
 */
class AvailabilityControllerTest extends TestCase
{
    use RefreshDatabase;

    private function listing(): Listing
    {
        return Listing::factory()->create([
            'is_published' => true,
            'accepts_inquiries' => true,
        ]);
    }

    private function unit(Listing $listing, array $attributes = []): BookableUnit
    {
        return BookableUnit::create([
            'listing_id' => $listing->id,
            'code' => 'standard',
            'name' => 'Standard Double',
            'max_adults' => 2,
            'max_children' => 0,
            'total_units' => 2,
            'base_rate' => 1200,
            'currency' => 'NAD',
            'is_active' => true,
            ...$attributes,
        ]);
    }

    private function slot(BookableUnit $unit, array $attributes = []): BookingSlot
    {
        return BookingSlot::create([
            'bookable_unit_id' => $unit->id,
            'label' => 'Morning ride',
            'starts_at' => '09:00:00',
            'duration_minutes' => 120,
            'is_active' => true,
            'sort' => 1,
            ...$attributes,
        ]);
    }

    private function url(
        Listing $listing,
        string $checkIn = '2026-09-01',
        ?string $checkOut = '2026-09-04',
        int $adults = 2,
        int $children = 0,
    ): string {
        $query = http_build_query(array_filter([
            'listing' => $listing->slug,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
            'adults' => $adults,
            'children' => $children ?: null,
        ]));

        return route('availability')."?{$query}";
    }

    public function test_date_range_unit_returns_periods_total_and_units_left(): void
    {
        $listing = $this->listing();
        $this->unit($listing, ['gallery' => ['room-types/gallery/double.jpg']]);

        $this->getJson($this->url($listing))
            ->assertOk()
            ->assertJsonPath('periods', 3)
            ->assertJsonPath('units.0.code', 'standard')
            ->assertJsonPath('units.0.total', 3600.0)
            ->assertJsonPath('units.0.units_left', 2)
            ->assertJsonCount(1, 'units.0.gallery')
            ->assertJsonMissingPath('units.0.slots.0');
    }

    public function test_slot_based_unit_returns_departures_instead_of_a_total(): void
    {
        $listing = $this->listing();
        $unit = $this->unit($listing, ['total_units' => 5]);
        $this->slot($unit);

        $this->getJson($this->url($listing, checkOut: null))
            ->assertOk()
            ->assertJsonPath('units.0.code', 'standard')
            ->assertJsonPath('units.0.total', null)
            ->assertJsonPath('units.0.units_left', null)
            ->assertJsonPath('units.0.slots.0.starts_at', '09:00')
            ->assertJsonPath('units.0.slots.0.label', 'Morning ride')
            ->assertJsonPath('units.0.slots.0.duration_minutes', 120)
            ->assertJsonPath('units.0.slots.0.units_left', 5)
            ->assertJsonPath('units.0.slots.0.total', 1200.0);
    }

    public function test_slot_without_a_label_uses_the_start_time(): void
    {
        $listing = $this->listing();
        $unit = $this->unit($listing);
        $this->slot($unit, ['label' => null]);

        $this->getJson($this->url($listing, checkOut: null))
            ->assertOk()
            ->assertJsonPath('units.0.slots.0.label', '09:00');
    }

    public function test_a_listing_with_no_units_returns_an_empty_array(): void
    {
        $this->getJson($this->url($this->listing()))
            ->assertOk()
            ->assertJsonCount(0, 'units');
    }

    public function test_an_inactive_unit_is_never_offered(): void
    {
        $listing = $this->listing();
        $this->unit($listing, ['is_active' => false]);

        $this->getJson($this->url($listing))
            ->assertOk()
            ->assertJsonCount(0, 'units');
    }

    public function test_an_unpublished_listing_is_not_readable(): void
    {
        $listing = Listing::factory()->create(['is_published' => false]);
        $this->unit($listing);

        $this->getJson($this->url($listing))->assertNotFound();
    }

    public function test_check_out_is_optional_and_defaults_to_one_day(): void
    {
        $listing = $this->listing();
        $this->unit($listing);

        // Without check_out the stay is treated as one period.
        $this->getJson($this->url($listing, checkOut: null))
            ->assertOk()
            ->assertJsonPath('periods', 1);
    }

    public function test_listing_slug_must_exist(): void
    {
        $this->getJson(route('availability').'?listing=does-not-exist&check_in=2026-09-01')
            ->assertStatus(422);
    }

    public function test_check_in_is_required(): void
    {
        $listing = $this->listing();

        $this->getJson(route('availability').'?listing='.$listing->slug)
            ->assertStatus(422);
    }
}
