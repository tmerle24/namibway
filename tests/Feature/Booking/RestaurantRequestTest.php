<?php

namespace Tests\Feature\Booking;

use App\Enums\InquiryKind;
use App\Enums\InquiryStatus;
use App\Enums\ListingType;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\MenuItem;
use App\Models\User;
use App\Services\Booking\StayPromoter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A restaurant is asked for two things a lodge is never asked for: a table on a
 * date at a time, and food off its menu. Both arrive as an `Inquiry` and travel
 * the pipeline every other request travels — what differs is which fields the
 * server requires, which it refuses, and what happens when one is confirmed.
 */
class RestaurantRequestTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $attributes */
    private function restaurant(array $attributes = []): Listing
    {
        return Listing::factory()->create([
            'type' => ListingType::Restaurant,
            'is_published' => true,
            'accepts_inquiries' => true,
            ...$attributes,
        ]);
    }

    private function lodge(): Listing
    {
        return Listing::factory()->create([
            'type' => ListingType::Accommodation,
            'is_published' => true,
            'accepts_inquiries' => true,
        ]);
    }

    /** @return array<string, string> */
    private function contact(): array
    {
        return ['name' => 'Traveler', 'email' => 'traveler@example.com'];
    }

    public function test_a_restaurant_page_is_sent_its_menu_in_reading_order(): void
    {
        // Viewing a listing queues an enrichment run when one is due, which on
        // the sync test queue would crawl the open internet.
        Queue::fake();

        $restaurant = $this->restaurant();

        MenuItem::factory()->for($restaurant)->create(['name' => 'Malva pudding', 'category' => 'Desserts', 'sort' => 2]);
        MenuItem::factory()->for($restaurant)->create(['name' => 'Kudu carpaccio', 'category' => 'Starters', 'sort' => 0]);
        MenuItem::factory()->for($restaurant)->create(['name' => 'Oryx fillet', 'category' => 'Mains', 'sort' => 1]);
        MenuItem::factory()->for($restaurant)->unavailable()->create(['name' => 'Sold out', 'category' => 'Mains', 'sort' => 1]);

        $this->get("/listings/{$restaurant->slug}")
            ->assertInertia(fn (Assert $page) => $page
                // Sections in the order their first item appears, not
                // alphabetically: a menu is written starters-first, and sorting
                // it would put Desserts above Mains on every restaurant in the
                // country.
                ->has('menu', 3)
                ->where('menu.0.category', 'Starters')
                ->where('menu.1.category', 'Mains')
                ->where('menu.2.category', 'Desserts')
                // Nothing the kitchen has switched off reaches the page.
                ->has('menu.1.items', 1)
                ->where('menu.1.items.0.name', 'Oryx fillet')
            );
    }

    public function test_a_listing_that_is_not_a_restaurant_gets_no_menu(): void
    {
        Queue::fake();

        $lodge = $this->lodge();

        $this->get("/listings/{$lodge->slug}")
            ->assertInertia(fn (Assert $page) => $page->where('menu', []));
    }

    public function test_a_table_reservation_records_its_date_and_time(): void
    {
        $restaurant = $this->restaurant();
        $date = now()->addWeek()->toDateString();

        $this->actingAs(User::factory()->create())
            ->post("/listings/{$restaurant->slug}/inquiries", [
                ...$this->contact(),
                'kind' => 'table_reservation',
                'check_in' => $date,
                'arrival_time' => '19:30',
                'adults' => 4,
                'children' => 1,
            ])
            ->assertSessionHasNoErrors();

        $inquiry = Inquiry::sole();

        $this->assertSame(InquiryKind::TableReservation, $inquiry->kind);
        $this->assertSame($date, $inquiry->check_in?->toDateString());
        $this->assertSame('19:30', $inquiry->arrivalTimeLabel());
        $this->assertNull($inquiry->check_out);
        $this->assertSame(4, $inquiry->adults);
    }

    public function test_a_table_reservation_must_say_when(): void
    {
        $restaurant = $this->restaurant();

        // "Dinner sometime" is not a booking a restaurant can hold, and asking
        // afterwards costs the partner an email the form could have saved them.
        $this->actingAs(User::factory()->create())
            ->post("/listings/{$restaurant->slug}/inquiries", $this->contact())
            ->assertSessionHasErrors(['check_in', 'arrival_time', 'adults']);

        $this->assertSame(0, Inquiry::count());
    }

    public function test_an_order_is_priced_from_the_menu_and_never_from_the_request(): void
    {
        $restaurant = $this->restaurant();
        $steak = MenuItem::factory()->for($restaurant)->create(['name' => 'Oryx fillet', 'price' => 245.00]);
        $beer = MenuItem::factory()->for($restaurant)->create(['name' => 'Windhoek Lager', 'price' => 35.50]);

        $this->actingAs(User::factory()->create())
            ->post("/listings/{$restaurant->slug}/inquiries", [
                ...$this->contact(),
                'kind' => 'order',
                'items' => [
                    // A posted name and price are ignored outright: a form that
                    // accepts a price is a form somebody can edit.
                    ['menu_item_id' => $steak->id, 'quantity' => 2, 'unit_price' => 1, 'name' => 'Free steak'],
                    ['menu_item_id' => $beer->id, 'quantity' => 3],
                ],
            ])
            ->assertSessionHasNoErrors();

        $inquiry = Inquiry::sole();

        $this->assertSame(InquiryKind::Order, $inquiry->kind);
        $this->assertEqualsWithDelta(2 * 245.00 + 3 * 35.50, (float) $inquiry->total_amount, 0.001);
        $this->assertSame('NAD', $inquiry->currency);

        $line = $inquiry->items()->where('menu_item_id', $steak->id)->sole();

        $this->assertSame('Oryx fillet', $line->name);
        $this->assertEqualsWithDelta(245.00, $line->unit_price, 0.001);
        $this->assertEqualsWithDelta(490.00, $line->line_total, 0.001);

        // No dates, no party — nothing the ask did not contain.
        $this->assertNull($inquiry->check_in);
        $this->assertNull($inquiry->arrival_time);
    }

    public function test_an_order_cannot_contain_something_that_is_off_the_menu(): void
    {
        $restaurant = $this->restaurant();
        MenuItem::factory()->for($restaurant)->create();
        $withdrawn = MenuItem::factory()->for($restaurant)->unavailable()->create();

        $this->actingAs(User::factory()->create())
            ->post("/listings/{$restaurant->slug}/inquiries", [
                ...$this->contact(),
                'kind' => 'order',
                'items' => [['menu_item_id' => $withdrawn->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Inquiry::count());
    }

    public function test_an_order_cannot_borrow_another_restaurants_menu(): void
    {
        $restaurant = $this->restaurant();
        MenuItem::factory()->for($restaurant)->create();
        $elsewhere = MenuItem::factory()->for($this->restaurant())->create();

        $this->actingAs(User::factory()->create())
            ->post("/listings/{$restaurant->slug}/inquiries", [
                ...$this->contact(),
                'kind' => 'order',
                'items' => [['menu_item_id' => $elsewhere->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Inquiry::count());
    }

    public function test_a_restaurant_with_no_menu_cannot_be_ordered_from(): void
    {
        $restaurant = $this->restaurant();

        // The page offers no order tab, and this is what makes that true rather
        // than merely hidden: the request falls back to a table booking, whose
        // own rules then refuse it for having no date.
        $this->actingAs(User::factory()->create())
            ->post("/listings/{$restaurant->slug}/inquiries", [
                ...$this->contact(),
                'kind' => 'order',
                'items' => [['menu_item_id' => 999, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors(['check_in', 'arrival_time']);

        $this->assertSame(0, Inquiry::count());
    }

    public function test_a_lodge_cannot_be_asked_for_a_table_or_an_order(): void
    {
        $lodge = $this->lodge();
        $checkIn = now()->addWeek()->toDateString();

        $this->actingAs(User::factory()->create())
            ->post("/listings/{$lodge->slug}/inquiries", [
                ...$this->contact(),
                'kind' => 'order',
                'check_in' => $checkIn,
                'check_out' => now()->addWeek()->addDays(2)->toDateString(),
                'items' => [['menu_item_id' => 1, 'quantity' => 1]],
            ])
            ->assertSessionHasNoErrors();

        $inquiry = Inquiry::sole();

        // The kind a listing may be asked for is the server's decision, and a
        // lodge is only ever asked for a stay.
        $this->assertSame(InquiryKind::Booking, $inquiry->kind);
        $this->assertSame(0, $inquiry->items()->count());
        $this->assertSame($checkIn, $inquiry->check_in?->toDateString());
    }

    public function test_a_restaurant_that_takes_no_orders_still_shows_its_menu(): void
    {
        Queue::fake();

        $restaurant = $this->restaurant(['accepts_orders' => false]);
        MenuItem::factory()->for($restaurant)->create(['name' => 'Oryx fillet']);

        // The whole point of the switch: the card is published, and reading it
        // is not the same as being able to order from it.
        $this->get("/listings/{$restaurant->slug}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_order', false)
                ->where('can_reserve_table', true)
                ->has('menu', 1)
                ->where('menu.0.items.0.name', 'Oryx fillet')
            );
    }

    public function test_an_order_is_refused_when_the_restaurant_does_not_take_orders(): void
    {
        $restaurant = $this->restaurant(['accepts_orders' => false]);
        $item = MenuItem::factory()->for($restaurant)->create();

        // Switched off is a rule, not a hidden tab: the request falls back to
        // the one channel that is open and is then refused on its own terms.
        $this->actingAs(User::factory()->create())
            ->post("/listings/{$restaurant->slug}/inquiries", [
                ...$this->contact(),
                'kind' => 'order',
                'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasErrors(['check_in', 'arrival_time']);

        $this->assertSame(0, Inquiry::count());
    }

    public function test_a_table_is_refused_when_the_restaurant_takes_no_reservations(): void
    {
        $restaurant = $this->restaurant(['accepts_table_reservations' => false]);
        $item = MenuItem::factory()->for($restaurant)->create(['price' => 120.00]);

        $this->actingAs(User::factory()->create())
            ->post("/listings/{$restaurant->slug}/inquiries", [
                ...$this->contact(),
                'kind' => 'table_reservation',
                'check_in' => now()->addWeek()->toDateString(),
                'arrival_time' => '19:00',
                'adults' => 2,
            ])
            ->assertSessionHasErrors('items');

        $this->assertSame(0, Inquiry::count());

        // Ordering, the one channel it does take, still works.
        $this->actingAs(User::factory()->create())
            ->post("/listings/{$restaurant->slug}/inquiries", [
                'name' => 'Traveler', 'email' => 'other@example.com',
                'kind' => 'order',
                'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(InquiryKind::Order, Inquiry::sole()->kind);
    }

    public function test_a_restaurant_with_both_channels_off_is_asked_for_nothing(): void
    {
        Queue::fake();

        $restaurant = $this->restaurant([
            'accepts_table_reservations' => false,
            'accepts_orders' => false,
        ]);
        MenuItem::factory()->for($restaurant)->create();

        $this->get("/listings/{$restaurant->slug}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_reserve_table', false)
                ->where('can_order', false)
                // The page says so through the same flag it has always used for
                // "this listing is not accepting inquiries".
                ->where('listing.accepts_inquiries', false)
            );

        // And the endpoint is closed, not merely unlinked.
        $this->actingAs(User::factory()->create())
            ->post("/listings/{$restaurant->slug}/inquiries", $this->contact())
            ->assertNotFound();

        $this->assertSame(0, Inquiry::count());
    }

    public function test_switching_orders_on_over_an_empty_menu_promises_nothing(): void
    {
        Queue::fake();

        // A toggle switched on with nothing to order is a promise the page
        // cannot keep, so it counts as off until a dish exists.
        $restaurant = $this->restaurant(['accepts_orders' => true]);

        $this->get("/listings/{$restaurant->slug}")
            ->assertInertia(fn (Assert $page) => $page->where('can_order', false));
    }

    public function test_the_toggles_do_not_reach_a_listing_that_is_not_a_restaurant(): void
    {
        Queue::fake();

        // The columns exist on every row and mean nothing outside a restaurant;
        // a lodge with both switched off is still asked for a stay.
        $lodge = Listing::factory()->create([
            'type' => ListingType::Accommodation,
            'is_published' => true,
            'accepts_inquiries' => true,
            'accepts_table_reservations' => false,
            'accepts_orders' => false,
        ]);

        $this->get("/listings/{$lodge->slug}")
            ->assertInertia(fn (Assert $page) => $page->where('listing.accepts_inquiries', true));

        $this->actingAs(User::factory()->create())
            ->post("/listings/{$lodge->slug}/inquiries", [
                ...$this->contact(),
                'check_in' => now()->addWeek()->toDateString(),
                'check_out' => now()->addWeek()->addDays(2)->toDateString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(InquiryKind::Booking, Inquiry::sole()->kind);
    }

    public function test_dinner_does_not_use_up_the_one_active_booking_request(): void
    {
        $restaurant = $this->restaurant();
        $lodge = $this->lodge();
        $item = MenuItem::factory()->for($restaurant)->create();
        $diner = User::factory()->create();
        $email = 'diner@example.com';

        // What the gate rations is a plan's worth of speculative requests going
        // out to lodges. A table and a plate of food go to one business that can
        // answer today, so neither may consume the traveller's one pipeline.
        $this->actingAs($diner)
            ->post("/listings/{$restaurant->slug}/inquiries", [
                'name' => 'Diner', 'email' => $email, 'kind' => 'order',
                'items' => [['menu_item_id' => $item->id, 'quantity' => 1]],
            ])->assertSessionHasNoErrors();

        $this->actingAs($diner)
            ->post("/listings/{$restaurant->slug}/inquiries", [
                'name' => 'Diner', 'email' => $email, 'kind' => 'table_reservation',
                'check_in' => now()->addWeek()->toDateString(),
                'arrival_time' => '20:00', 'adults' => 2,
            ])->assertSessionHasNoErrors();

        $this->actingAs($diner)
            ->post("/listings/{$lodge->slug}/inquiries", [
                'name' => 'Diner', 'email' => $email,
                'check_in' => now()->addWeek()->toDateString(),
                'check_out' => now()->addWeek()->addDays(2)->toDateString(),
            ])->assertSessionHasNoErrors();

        $this->assertSame(3, Inquiry::where('email', $email)->count());

        // The rule itself is untouched: a second stay request is still refused.
        $this->actingAs($diner)
            ->post("/listings/{$lodge->slug}/inquiries", [
                'name' => 'Diner', 'email' => $email,
                'check_in' => now()->addMonth()->toDateString(),
                'check_out' => now()->addMonth()->addDays(2)->toDateString(),
            ])->assertSessionHasErrors('email');

        $this->assertSame(1, Inquiry::where('email', $email)->where('kind', InquiryKind::Booking)->count());
    }

    public function test_confirming_a_restaurant_request_does_not_alert_the_team(): void
    {
        Mail::fake();

        $restaurant = $this->restaurant();
        $item = MenuItem::factory()->for($restaurant)->create();

        $order = $restaurant->inquiries()->create([
            ...$this->contact(),
            'kind' => InquiryKind::Order,
            'status' => InquiryStatus::OnRequest,
        ]);
        $order->items()->create([
            'menu_item_id' => $item->id,
            'name' => $item->name,
            'quantity' => 1,
            'unit_price' => $item->price,
            'line_total' => $item->price,
            'currency' => 'NAD',
        ]);

        // Neither a table nor an order allocates a unit for a range of nights,
        // so there is nothing to write to the calendar — and nothing that
        // failed. An alert that fires on a normal day is an alert nobody reads
        // on the day it matters.
        $this->assertNull(app(StayPromoter::class)->promoteQuietly($order));

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }
}
