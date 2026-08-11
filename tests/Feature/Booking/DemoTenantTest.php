<?php

namespace Tests\Feature\Booking;

use App\Enums\ListingType;
use App\Enums\ReservationSource;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use App\Services\Demo\DemoTenantBuilder;
use App\Services\Inventory\DTOs\BookingLine;
use App\Services\Inventory\DTOs\BookingRequest;
use App\Services\Inventory\InventoryWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The demo tenant is the piece with the sharpest failure mode: it is built
 * from a real business's data, and it is handed to a stranger in a meeting.
 * So what is tested here is mostly what it must *not* do — touch the real
 * listing, inherit a way of reaching the real owner, or accumulate a second
 * layer of stays every time it is rebuilt.
 */
class DemoTenantTest extends TestCase
{
    use RefreshDatabase;

    private function source(): Listing
    {
        $partner = Partner::create([
            'name' => 'Okonjima Nature Reserve',
            'email' => 'owner@okonjima.example',
            'phone' => '+264 61 000 000',
            'website' => 'https://okonjima.example',
        ]);

        $listing = Listing::factory()->create([
            'partner_id' => $partner->id,
            'type' => ListingType::Accommodation,
            'name' => 'Okonjima Bush Camp',
            'slug' => 'okonjima-bush-camp',
            'is_published' => true,
            'accepts_inquiries' => true,
            'contact_email' => 'reservations@okonjima.example',
            'phone' => '+264 61 000 001',
        ]);

        foreach ([['STD', 6, 1850.0], ['LUX', 3, 4200.0]] as [$code, $units, $rate]) {
            RoomType::factory()->create([
                'listing_id' => $listing->id,
                'code' => $code,
                'name' => $code === 'STD' ? 'Standard Chalet' : 'Luxury Suite',
                'total_units' => $units,
                'rate_per_night' => $rate,
                'currency' => 'NAD',
                'is_active' => true,
            ]);
        }

        return $listing->refresh();
    }

    private function builder(): DemoTenantBuilder
    {
        return app(DemoTenantBuilder::class);
    }

    public function test_building_a_demo_tenant_does_not_modify_the_source_listing(): void
    {
        $source = $this->source();

        $before = $source->getAttributes();
        $roomsBefore = $source->roomTypes()->orderBy('code')->get()
            ->map(fn (RoomType $room) => $room->getAttributes())->all();

        $this->builder()->build($source, weeks: 4);

        $source->refresh();

        $this->assertSame($before, $source->getAttributes(), 'The source listing was written to.');
        $this->assertSame(
            $roomsBefore,
            $source->roomTypes()->orderBy('code')->get()->map(fn (RoomType $room) => $room->getAttributes())->all(),
            'The source listing’s room types were written to.',
        );

        // And nothing was hung off the real listing either.
        $this->assertSame(0, Reservation::where('listing_id', $source->id)->count());
        $this->assertSame(2, $source->roomTypes()->count());
    }

    public function test_the_demo_inherits_no_way_of_reaching_the_real_business(): void
    {
        $source = $this->source();

        $tenant = $this->builder()->build($source, weeks: 4);

        $this->assertTrue($tenant->partner->is_demo);
        $this->assertNull($tenant->partner->email);
        $this->assertNull($tenant->partner->phone);
        $this->assertNull($tenant->partner->website);
        $this->assertNull($tenant->partner->connector_type);
        $this->assertNull($tenant->partner->connector_verified_at);

        // A demo must not be reachable by a traveller and must not take a real
        // booking request.
        $this->assertFalse($tenant->listing->is_published);
        $this->assertFalse($tenant->listing->accepts_inquiries);

        // No address anywhere in the tenant points outside the domain we
        // control — which is what makes an outbound-suppression layer
        // unnecessary rather than merely absent.
        $domain = config('booking.demo.email_domain');
        $addresses = Reservation::query()
            ->where('listing_id', $tenant->listing->id)
            ->whereNotNull('guest_email')
            ->pluck('guest_email')
            ->push($tenant->user->email);

        $this->assertNotEmpty($addresses);

        foreach ($addresses as $address) {
            $this->assertStringEndsWith('@'.$domain, (string) $address);
        }
    }

    public function test_a_demo_tenant_looks_like_a_working_business(): void
    {
        $source = $this->source();

        $tenant = $this->builder()->build($source, weeks: 6);

        $this->assertSame(2, $tenant->roomTypes);
        $this->assertGreaterThan(10, $tenant->stays);
        $this->assertGreaterThan(0, $tenant->blocks);

        // Copied, not referenced: the demo's rooms belong to the demo's own
        // listing, so editing them cannot reach real content.
        foreach ($tenant->listing->roomTypes as $room) {
            $this->assertSame($tenant->listing->id, $room->listing_id);
            $this->assertNotSame($source->id, $room->listing_id);
        }
    }

    public function test_rebuilding_leaves_one_clean_complete_tenant(): void
    {
        $source = $this->source();

        $first = $this->builder()->build($source, weeks: 4);
        $firstStays = $first->stays;

        // A prospect makes a mess: an extra booking of their own.
        app(InventoryWriter::class)->book(new BookingRequest(
            listing: $first->listing,
            lines: [new BookingLine(
                $first->listing->roomTypes()->first(),
                1,
                now()->addDays(60),
                now()->addDays(62),
            )],
            guestName: 'Prospect Was Here',
            source: ReservationSource::WalkIn,
        ));

        $second = $this->builder()->build($source, weeks: 4);

        // One tenant, not two.
        $this->assertSame(1, Partner::where('is_demo', true)->count());
        $this->assertSame($first->partner->id, $second->partner->id);
        $this->assertSame($first->listing->id, $second->listing->id);
        $this->assertSame(1, Listing::where('partner_id', $second->partner->id)->count());
        $this->assertSame(1, User::where('partner_id', $second->partner->id)->count());

        // Clean: the prospect's booking is gone, and the tenant is complete
        // again rather than a second layer of stays on top of the first.
        $this->assertSame(
            0,
            Reservation::where('listing_id', $second->listing->id)->where('guest_name', 'Prospect Was Here')->count(),
        );
        $this->assertSame($firstStays, $second->stays);
        $this->assertSame(
            $second->stays,
            Reservation::where('listing_id', $second->listing->id)->count(),
        );
        $this->assertSame(2, $second->listing->roomTypes()->count());

        // A password from the previous meeting stops working.
        $this->assertNotSame($first->password, $second->password);
    }

    public function test_a_demo_tenant_can_be_removed_entirely(): void
    {
        $source = $this->source();
        $tenant = $this->builder()->build($source, weeks: 3);

        $this->builder()->destroy($tenant->partner);

        $this->assertSame(0, Partner::where('is_demo', true)->count());
        $this->assertSame(0, Listing::where('id', $tenant->listing->id)->count());
        $this->assertSame(0, Reservation::where('listing_id', $tenant->listing->id)->count());

        // And the real business is still there.
        $this->assertSame(1, Listing::where('id', $source->id)->count());
        $this->assertSame(2, $source->roomTypes()->count());
    }

    public function test_the_sign_in_link_opens_the_demo_and_refuses_a_real_account(): void
    {
        $source = $this->source();
        $tenant = $this->builder()->build($source, weeks: 3);

        $this->get($tenant->signInUrl)->assertRedirect();
        $this->assertAuthenticatedAs($tenant->user);

        auth()->logout();

        $realUser = User::factory()->create([
            'partner_id' => Partner::create(['name' => 'A Real Lodge'])->id,
        ]);

        $this->get(URL::temporarySignedRoute(
            'partner.demo.sign-in',
            now()->addDay(),
            ['user' => $realUser->id],
        ))->assertForbidden();

        $this->assertGuest();
    }

    public function test_an_unsigned_or_expired_sign_in_link_is_refused(): void
    {
        $source = $this->source();
        $tenant = $this->builder()->build($source, weeks: 3);

        $this->get(route('partner.demo.sign-in', ['user' => $tenant->user->id]))->assertForbidden();
        $this->assertGuest();

        $expired = URL::temporarySignedRoute(
            'partner.demo.sign-in',
            now()->subMinute(),
            ['user' => $tenant->user->id],
        );

        $this->get($expired)->assertForbidden();
        $this->assertGuest();
    }

    public function test_the_command_refuses_a_listing_with_no_room_types(): void
    {
        $listing = Listing::factory()->create([
            'type' => ListingType::Accommodation,
            'slug' => 'no-rooms-here',
        ]);

        $this->artisan('booking:demo-tenant', ['listing' => $listing->slug])
            ->assertExitCode(1);

        $this->assertSame(0, Partner::where('is_demo', true)->count());
    }

    public function test_the_command_refuses_to_build_a_demo_from_a_demo(): void
    {
        $source = $this->source();
        $tenant = $this->builder()->build($source, weeks: 3);

        $this->artisan('booking:demo-tenant', ['listing' => $tenant->listing->slug])
            ->assertExitCode(2);

        $this->assertSame(1, Partner::where('is_demo', true)->count());
    }
}
