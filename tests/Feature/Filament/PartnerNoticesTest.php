<?php

namespace Tests\Feature\Filament;

use App\Enums\ListingType;
use App\Enums\PricingStrategy;
use App\Filament\Partner\Support\Notice;
use App\Filament\Partner\Support\PropertyNotices;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\RatePlan;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Inventory\InventoryWriter;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * What the panel tells an operator, and where it tells them.
 *
 * The subject used to be a strip above every screen. It is now a board on the
 * dashboard, so what is worth testing is that the sentences still appear when
 * they are true, still disappear when they are not, and now carry somewhere to
 * go — a notice with no answer to "so what do I do" is the thing this replaced.
 */
class PartnerNoticesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_property_that_is_not_live_says_so_on_the_dashboard_with_a_way_to_change_it(): void
    {
        [$user, $listing] = $this->partnerWithProperty();
        $this->roomType($listing);

        $this->actingAs($user)
            ->get('/partner')
            ->assertOk()
            ->assertSee('Bookings are not live yet')
            ->assertSee('How this is switched on')
            ->assertSee('/partner/getting-started', escape: false);
    }

    public function test_the_notice_is_gone_once_the_property_is_live(): void
    {
        [$user, $listing] = $this->partnerWithProperty();
        $this->roomType($listing);

        $listing->partner?->update(['booking_enabled' => true]);

        $this->actingAs($user)
            ->get('/partner')
            ->assertOk()
            ->assertDontSee('Bookings are not live yet')
            ->assertDontSee('Worth knowing');
    }

    public function test_test_mode_is_a_note_rather_than_a_warning(): void
    {
        [, $listing] = $this->partnerWithProperty();
        $this->roomType($listing);

        $listing->partner?->update([
            'booking_enabled' => true,
            'booking_demo_mode' => true,
            'booking_demo_email' => 'operator@example.com',
        ]);

        $notice = $this->notices($listing->fresh())[0];

        $this->assertSame('booking-state', $notice->key);
        $this->assertSame('info', $notice->level->value);
        $this->assertStringContainsString('operator@example.com', $notice->body);
    }

    public function test_a_property_with_no_rooms_is_told_where_to_start(): void
    {
        [$user, $listing] = $this->partnerWithProperty();

        $this->actingAs($user)
            ->get('/partner')
            ->assertOk()
            ->assertSee('Add the rooms you sell');

        $this->roomType($listing);

        $this->actingAs($user)
            ->get('/partner')
            ->assertOk()
            ->assertDontSee('Add the rooms you sell');
    }

    public function test_a_room_nobody_priced_is_named_before_a_guest_is_standing_there(): void
    {
        [, $listing] = $this->partnerWithProperty();
        $this->roomType($listing, ['name' => 'Riverside Chalet', 'rate_per_night' => 0]);

        $notice = $this->noticeKeyed($listing, 'no-prices');

        $this->assertNotNull($notice);
        $this->assertStringContainsString('Riverside Chalet', $notice->body);
    }

    public function test_a_rate_set_on_a_plan_counts_as_a_price(): void
    {
        [, $listing] = $this->partnerWithProperty();
        $room = $this->roomType($listing, ['rate_per_night' => 0]);

        $plan = RatePlan::create([
            'listing_id' => $listing->id,
            'name' => 'Standard rate',
            'code' => 'STD',
            'pricing_strategy' => PricingStrategy::PerUnit,
            'is_default' => true,
        ]);

        app(InventoryWriter::class)->setRates(
            $plan,
            $room,
            Carbon::parse('2026-09-10'),
            Carbon::parse('2026-09-10'),
            ['rate' => 1800],
        );

        $this->assertNull($this->noticeKeyed($listing, 'no-prices'));
    }

    /**
     * The checklist is derived every time it is read, so a step that was done
     * and then undone stops claiming otherwise.
     */
    public function test_the_getting_started_page_checks_itself(): void
    {
        [$user, $listing] = $this->partnerWithProperty();

        $this->actingAs($user)
            ->get('/partner/getting-started')
            ->assertOk()
            ->assertSee('Add the rooms you sell')
            ->assertSee('Not live yet')
            ->assertSee('This property, now')
            ->assertSee('team+'.$listing->slug.'@namibway.com');
    }

    public function test_a_partner_with_no_property_gets_an_explanation_not_an_error(): void
    {
        $partner = Partner::create(['name' => 'New', 'email' => 'new@example.com']);
        $user = User::factory()->create(['partner_id' => $partner->id]);

        $this->actingAs($user)
            ->get('/partner/getting-started')
            ->assertOk()
            ->assertSee('No property is linked to this account yet');
    }

    /** @return array<int, Notice> */
    private function notices(?Listing $listing): array
    {
        Filament::setCurrentPanel(Filament::getPanel('partner'));

        return app(PropertyNotices::class)->for($listing);
    }

    private function noticeKeyed(Listing $listing, string $key): ?Notice
    {
        foreach ($this->notices($listing) as $notice) {
            if ($notice->key === $key) {
                return $notice;
            }
        }

        return null;
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function partnerWithProperty(): array
    {
        $partner = Partner::create(['name' => 'Camp', 'email' => fake()->unique()->safeEmail()]);
        $user = User::factory()->create(['partner_id' => $partner->id]);

        $listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
            'name' => 'Camp',
        ]);

        return [$user, $listing->fresh() ?? $listing];
    }

    /** @param array<string, mixed> $attributes */
    private function roomType(Listing $listing, array $attributes = []): RoomType
    {
        return RoomType::factory()->create(array_merge([
            'listing_id' => $listing->id,
            'total_units' => 3,
            'rate_per_night' => 1500.00,
            'currency' => 'NAD',
            'is_active' => true,
        ], $attributes));
    }
}
