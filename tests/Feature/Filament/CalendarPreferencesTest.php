<?php

namespace Tests\Feature\Filament;

use App\Enums\CalendarRange;
use App\Enums\ListingType;
use App\Filament\Partner\Pages\OccupancyCalendar;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\BookableUnit;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\RatePlan;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The calendar opens the way it was left.
 *
 * A wall planner is set up once and then lived in, so the *reading* — range,
 * resolution, rate plan — is remembered on the account. The date never is, and
 * a link somebody was sent never rewrites what they set for themselves. See
 * App\Filament\Partner\Support\CalendarPreferences.
 */
class CalendarPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // A Thursday, mid-month, so a remembered week is visibly not the month.
        Carbon::setTestNow(Carbon::parse('2026-09-10 09:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_range_survives_leaving_the_page(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $this->bookableUnit($listing);

        $this->panelAs($user);

        Livewire::test(OccupancyCalendar::class)->call('showRange', 'week');

        $this->assertSame('week', $user->fresh()?->preference('partner.calendar.range'));

        // A fresh visit, nothing in the query string.
        $page = Livewire::test(OccupancyCalendar::class);

        $this->assertSame(CalendarRange::Week, $page->instance()->range());
        $this->assertSame('2026-09-07', $page->instance()->grid()?->from->toDateString(), 'This week, not the week it was set in.');

        $this->get('/partner/calendar')->assertOk()->assertSee('7 Sep – 13 Sep 2026');
    }

    public function test_the_calendar_offers_desk_mode(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $this->bookableUnit($listing);

        $this->actingAs($user)
            ->get('/partner/calendar')
            ->assertOk()
            ->assertSee('Desk mode')
            ->assertSee('nw-lodge--desk', escape: false);
    }

    public function test_the_date_is_not_remembered(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $this->bookableUnit($listing);

        $this->panelAs($user);

        Livewire::test(OccupancyCalendar::class)->call('jumpTo', 2, 2028);

        $page = Livewire::test(OccupancyCalendar::class);

        $this->assertSame('2026-09-01', $page->instance()->grid()?->from->toDateString(), 'A calendar opens on today, not on where somebody was reading.');
    }

    public function test_a_link_shows_its_own_range_without_rewriting_the_operators(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $this->bookableUnit($listing);

        $this->panelAs($user);

        Livewire::test(OccupancyCalendar::class)->call('showRange', 'week');

        // A colleague's link. It wins for the page it addresses...
        $this->get('/partner/calendar?range=day')
            ->assertOk()
            ->assertSee('Thursday 10 September 2026');

        // ...and leaves this operator's own calendar as they set it.
        $this->assertSame('week', $user->fresh()?->preference('partner.calendar.range'));
        $this->assertSame(CalendarRange::Week, Livewire::test(OccupancyCalendar::class)->instance()->range());
    }

    public function test_the_day_resolution_is_remembered_and_nonsense_is_not(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $this->bookableUnit($listing);

        $this->panelAs($user);

        Livewire::test(OccupancyCalendar::class)->call('showResolution', 15);

        $this->assertSame(15, $user->fresh()?->preference('partner.calendar.resolution'));
        $this->assertSame(15, Livewire::test(OccupancyCalendar::class)->instance()->resolution);

        Livewire::test(OccupancyCalendar::class)->call('showResolution', 7);

        $this->assertNull($user->fresh()?->preference('partner.calendar.resolution'), 'A resolution the day grid cannot draw is forgotten, not stored.');
        $this->assertNull(Livewire::test(OccupancyCalendar::class)->instance()->resolution);
    }

    public function test_the_rate_plan_is_remembered_per_property(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $this->bookableUnit($listing);

        $default = RatePlan::ensureDefaultFor($listing);
        $special = RatePlan::create([
            'listing_id' => $listing->id,
            'name' => 'Resident rate',
            'code' => 'RES',
        ]);

        $this->panelAs($user);

        Livewire::test(OccupancyCalendar::class)->call('showRatePlan', $special->id);

        $this->assertSame($special->id, $user->fresh()?->preference('partner.calendar.rate_plan.'.$listing->id));
        $this->assertSame($special->id, Livewire::test(OccupancyCalendar::class)->instance()->shownRatePlan()?->id);

        // A plan that goes away falls back to the default rather than to nothing.
        $special->delete();

        $this->assertSame($default->id, Livewire::test(OccupancyCalendar::class)->instance()->shownRatePlan()?->id);
    }

    public function test_preferences_belong_to_the_person_not_the_property(): void
    {
        [$user, $listing] = $this->partnerWithProperty('Etosha Camp');
        $this->bookableUnit($listing);

        $colleague = User::factory()->create(['partner_id' => $user->partner_id]);

        $this->panelAs($user);
        Livewire::test(OccupancyCalendar::class)->call('showRange', 'day');

        $this->panelAs($colleague);

        $this->assertSame(
            CalendarRange::Month,
            Livewire::test(OccupancyCalendar::class)->instance()->range(),
            'Preferences are the person’s, not the property’s.',
        );
    }

    public function test_the_chosen_property_outlives_the_session(): void
    {
        [$user, $first] = $this->partnerWithProperty('Camp One');
        $second = Listing::factory()->create([
            'partner_id' => $first->partner_id,
            'type' => ListingType::Accommodation,
            'name' => 'Camp Two',
        ]);

        $this->actingAs($user)
            ->from('/partner/calendar')
            ->post(route('filament.partner.property.select'), ['property' => $second->id])
            ->assertRedirect('/partner/calendar');

        // A new session entirely, the way tomorrow morning arrives.
        $this->flushSession();
        $this->panelAs($user);

        $this->assertSame($second->id, app(SelectedProperty::class)->current()?->id);
        $this->assertSame($second->id, $user->fresh()?->preference(SelectedProperty::PREFERENCE_KEY));
    }

    private function panelAs(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('partner'));
    }

    /**
     * @return array{0: User, 1: Listing}
     */
    private function partnerWithProperty(string $name): array
    {
        $partner = Partner::create(['name' => $name, 'email' => fake()->unique()->safeEmail()]);
        $user = User::factory()->create(['partner_id' => $partner->id]);

        $listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
            'name' => $name,
        ]);

        return [$user, $listing];
    }

    private function bookableUnit(Listing $listing): BookableUnit
    {
        return BookableUnit::factory()->create([
            'listing_id' => $listing->id,
            'total_units' => 3,
            'rate_per_night' => 1500.00,
            'currency' => 'NAD',
        ]);
    }
}
