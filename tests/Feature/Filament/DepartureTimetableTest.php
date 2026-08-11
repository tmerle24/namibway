<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ListingResource\Pages\EditListing;
use App\Filament\Resources\ListingResource\RelationManagers\RoomTypesRelationManager;
use App\Models\BookingSlot;
use App\Models\Listing;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Entering the timetable a unit runs.
 *
 * It lives inside the unit rather than on a screen of its own, because a
 * departure has no meaning apart from the thing it departs — and because a nav
 * item called "Departures" would be one more heading every lodge in the panel
 * has to read past. The rule this whole design is judged by is that unused
 * things stay invisible, and a property selling nights leaves the section
 * closed and empty forever.
 */
class DepartureTimetableTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_operator_enters_a_timetable_on_the_unit_that_runs_it(): void
    {
        [$user, $listing] = $this->adminAndProperty();
        $tour = $this->unit($listing, 'Quad tour');

        $this->actingAs($user);

        Livewire::test(RoomTypesRelationManager::class, [
            'ownerRecord' => $listing,
            'pageClass' => EditListing::class,
        ])
            ->mountTableAction('edit', $tour)
            ->setTableActionData([
                'slots' => [
                    ['starts_at' => '09:00', 'duration_minutes' => 180, 'label' => 'Morning ride', 'is_active' => true],
                    ['starts_at' => '14:00', 'duration_minutes' => 180, 'label' => 'Afternoon ride', 'is_active' => true],
                ],
            ])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $slots = BookingSlot::forUnit($tour->refresh());

        $this->assertCount(2, $slots);
        $this->assertSame('09:00', $slots[0]->timeLabel());
        $this->assertSame('Morning ride', $slots[0]->label());
        $this->assertSame(180, $slots[0]->duration_minutes);
        $this->assertSame('14:00', $slots[1]->timeLabel());
    }

    /**
     * The database refuses this too — there is a unique index — but a
     * constraint violation is not something to show a lodge manager who was
     * entering a timetable.
     */
    public function test_two_departures_at_the_same_time_are_refused_in_words(): void
    {
        [$user, $listing] = $this->adminAndProperty();
        $tour = $this->unit($listing, 'Quad tour');

        $this->actingAs($user);

        Livewire::test(RoomTypesRelationManager::class, [
            'ownerRecord' => $listing,
            'pageClass' => EditListing::class,
        ])
            ->mountTableAction('edit', $tour)
            ->setTableActionData([
                'slots' => [
                    ['starts_at' => '09:00', 'duration_minutes' => 180, 'label' => 'Morning ride', 'is_active' => true],
                    ['starts_at' => '09:00', 'duration_minutes' => 120, 'label' => 'The other one', 'is_active' => true],
                ],
            ])
            ->callMountedTableAction()
            ->assertHasTableActionErrors();

        $this->assertSame(0, BookingSlot::query()->where('room_type_id', $tour->id)->count());
    }

    /** A property that sells nights never has to know any of this exists. */
    public function test_a_room_sold_by_the_night_keeps_an_empty_timetable(): void
    {
        [$user, $listing] = $this->adminAndProperty();
        $chalet = $this->unit($listing, 'Bush Chalet');

        $this->actingAs($user);

        Livewire::test(RoomTypesRelationManager::class, [
            'ownerRecord' => $listing,
            'pageClass' => EditListing::class,
        ])
            ->mountTableAction('edit', $chalet)
            ->setTableActionData(['name' => 'Bush Chalet Deluxe'])
            ->callMountedTableAction()
            ->assertHasNoTableActionErrors();

        $this->assertSame('Bush Chalet Deluxe', $chalet->refresh()->name);
        $this->assertFalse($chalet->hasSlots());
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function adminAndProperty(): array
    {
        return [User::factory()->create(['is_admin' => true]), Listing::factory()->create()];
    }

    private function unit(Listing $listing, string $name): RoomType
    {
        return RoomType::factory()->create([
            'listing_id' => $listing->id,
            'name' => $name,
            'total_units' => 8,
            'rate_per_night' => 950,
            'currency' => 'NAD',
        ]);
    }
}
