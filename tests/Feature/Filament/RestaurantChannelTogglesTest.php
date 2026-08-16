<?php

namespace Tests\Feature\Filament;

use App\Enums\ListingType;
use App\Filament\Resources\ListingResource\Pages\EditListing;
use App\Models\Listing;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two switches a restaurant owns: whether it takes table bookings online,
 * and whether it takes orders.
 *
 * Both were derived before — a table from `accepts_inquiries` alone, ordering
 * from the mere existence of a menu — which left a restaurant no way to publish
 * its card without also agreeing to take orders from it.
 *
 * What is pinned here is the part a unit test cannot see: that Filament's own
 * evaluation of the visibility closure reaches the right answer, so the fields
 * appear on a restaurant and on nothing else. The behaviour behind them lives
 * in Tests\Feature\Booking\RestaurantRequestTest.
 */
class RestaurantChannelTogglesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_toggles_are_offered_on_a_restaurant(): void
    {
        [$user, $restaurant] = $this->adminAndProperty(ListingType::Restaurant);

        Livewire::actingAs($user)
            ->test(EditListing::class, ['record' => $restaurant->getRouteKey()])
            ->assertFormFieldIsVisible('accepts_table_reservations')
            ->assertFormFieldIsVisible('accepts_orders');
    }

    public function test_the_toggles_are_hidden_on_everything_else(): void
    {
        [$user, $lodge] = $this->adminAndProperty(ListingType::Accommodation);

        Livewire::actingAs($user)
            ->test(EditListing::class, ['record' => $lodge->getRouteKey()])
            ->assertFormFieldIsHidden('accepts_table_reservations')
            ->assertFormFieldIsHidden('accepts_orders');
    }

    public function test_switching_a_channel_off_is_saved(): void
    {
        [$user, $restaurant] = $this->adminAndProperty(ListingType::Restaurant);

        Livewire::actingAs($user)
            ->test(EditListing::class, ['record' => $restaurant->getRouteKey()])
            ->fillForm(['accepts_orders' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $restaurant->refresh();

        $this->assertFalse($restaurant->accepts_orders);
        // Untouched by the other switch — they are two decisions, not one.
        $this->assertTrue($restaurant->accepts_table_reservations);
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function adminAndProperty(ListingType $type): array
    {
        return [
            User::factory()->create(['is_admin' => true]),
            Listing::factory()->create([
                'type' => $type,
                // Coordinates on purpose: EditListing::mutateFormDataBeforeSave
                // geocodes a listing that has none, and a test has no business
                // reaching Nominatim. Same reason as ListingRoomTypesTabTest.
                'latitude' => -22.57,
                'longitude' => 17.08,
            ]),
        ];
    }
}
