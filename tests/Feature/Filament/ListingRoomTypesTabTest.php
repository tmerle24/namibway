<?php

namespace Tests\Feature\Filament;

use App\Enums\ListingType;
use App\Filament\Partner\Resources\ListingResource as PartnerListingResource;
use App\Filament\Resources\ListingResource;
use App\Filament\Resources\ListingResource\Pages\EditListing;
use App\Filament\Resources\ListingResource\RelationManagers\BookableUnitsRelationManager;
use App\Models\BookableUnit;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Room types are edited inside the listing form's own tab strip in /admin,
 * rather than in a relation manager below it.
 *
 * The frame had to change to get there: a relation manager renders as a
 * sibling of the edit <form> and cannot be moved into it, because Filament's
 * table action modals are themselves <form> elements and nesting those breaks
 * submission in the browser without saying so. So /admin uses a relationship
 * Repeater over the same BookableUnitSchema, and what these tests pin is that
 * the swap actually writes the same rows — including the departures, which
 * ride along as a relationship repeater nested inside another one.
 */
class ListingRoomTypesTabTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_room_type_is_entered_from_the_listings_own_form(): void
    {
        [$user, $listing] = $this->adminAndProperty();

        Livewire::actingAs($user)
            ->test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->fillForm(['bookableUnits' => [$this->unitData()]])
            ->call('save')
            ->assertHasNoFormErrors();

        $unit = BookableUnit::where('listing_id', $listing->id)->sole();

        $this->assertSame('Luxury Chalet', $unit->name);
        $this->assertSame('LUX', $unit->code);
        $this->assertSame(4, $unit->total_units);
        $this->assertSame('2500.00', $unit->base_rate);
        $this->assertSame('NAD', $unit->currency);
        $this->assertTrue($unit->is_active);
    }

    /**
     * The riskiest part of the move: departures are a relationship repeater
     * inside the room-type repeater, so they only save if Filament carries the
     * nesting all the way down. A silent failure here would look exactly like
     * a lodge forgetting to press save.
     */
    public function test_a_departure_entered_on_a_new_room_type_saves_with_it(): void
    {
        [$user, $listing] = $this->adminAndProperty(ListingType::Activity);

        Livewire::actingAs($user)
            ->test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->fillForm(['bookableUnits' => [
                $this->unitData([
                    'name' => 'Quad tour',
                    'code' => 'QUAD',
                    'slots' => [
                        ['starts_at' => '09:00', 'duration_minutes' => 180, 'label' => 'Morning ride', 'is_active' => true],
                    ],
                ]),
            ]])
            ->call('save')
            ->assertHasNoFormErrors();

        $unit = BookableUnit::where('listing_id', $listing->id)->sole();
        $slots = BookingSlot::forUnit($unit);

        $this->assertCount(1, $slots);
        $this->assertSame('09:00', $slots[0]->timeLabel());
        $this->assertSame('Morning ride', $slots[0]->label());
    }

    /** Rooms already entered are what the tab opens on. */
    public function test_the_form_loads_the_room_types_the_property_already_has(): void
    {
        [$user, $listing] = $this->adminAndProperty();

        $unit = BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'name' => 'Bush Chalet',
            'currency' => 'NAD',
        ]);

        $state = Livewire::actingAs($user)
            ->test(EditListing::class, ['record' => $listing->getRouteKey()])
            ->get('data.bookableUnits');

        $this->assertIsArray($state);
        $this->assertCount(1, $state);

        $first = collect($state)->first();

        $this->assertSame('Bush Chalet', $first['name']);
        $this->assertSame($unit->code, $first['code']);
    }

    /**
     * Both halves of the swap, in one place: nothing renders room types below
     * the /admin form any more, and the partner panel — whose form is sections
     * rather than tabs, so it has no strip to move them into — keeps the
     * relation manager it always had.
     */
    public function test_admin_shows_room_types_only_in_the_tab_and_the_partner_panel_keeps_its_relation_manager(): void
    {
        $this->assertSame([], ListingResource::getRelations());
        $this->assertContains(BookableUnitsRelationManager::class, PartnerListingResource::getRelations());
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function adminAndProperty(ListingType $type = ListingType::Accommodation): array
    {
        return [
            User::factory()->create(['is_admin' => true]),
            Listing::factory()->create([
                // Pinned, not left to the factory's random ListingType: saving
                // the form validates the whole record, and a listing that came
                // out as a vehicle fails on the vehicle_category the factory
                // does not set — a room-types test that passes three times in
                // four. This is what turned main red after #150.
                'type' => $type,
                // Coordinates on purpose: EditListing::mutateFormDataBeforeSave
                // geocodes a listing that has none, and a test has no business
                // reaching Nominatim.
                'latitude' => -22.57,
                'longitude' => 17.08,
            ]),
        ];
    }

    /**
     * Every field the schema requires — fillForm() replaces the repeater's
     * state wholesale, so component defaults do not fill the gaps.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function unitData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Luxury Chalet',
            'code' => 'LUX',
            'description' => null,
            'max_adults' => 2,
            'max_children' => 0,
            'total_units' => 4,
            'base_rate' => 2500,
            'currency' => 'NAD',
            'is_active' => true,
        ], $overrides);
    }
}
