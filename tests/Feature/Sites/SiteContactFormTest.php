<?php

namespace Tests\Feature\Sites;

use App\Enums\InquiryKind;
use App\Enums\ListingType;
use App\Models\Inquiry;
use App\Models\Listing;
use App\Models\MenuItem;
use App\Models\Partner;
use App\Models\ShopProduct;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\Blocks\EnquiryBlock;
use App\Sites\Blocks\EnquiryFormType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * One contact form per customer website, chosen by the owner.
 *
 * The five types are a presentation choice — see `EnquiryFormType` — and each
 * one asks for a different set of things. What is pinned here is that the
 * server enforces the choice rather than trusting the page: which fields are
 * required, which are refused, and above all that an order is priced from the
 * business's own rows and never from what the browser posted.
 */
class SiteContactFormTest extends TestCase
{
    use RefreshDatabase;

    private function siteFor(
        EnquiryFormType $type,
        ?Listing $listing = null,
        ?Partner $partner = null,
        string $channel = EnquiryBlock::CHANNEL_EMAIL,
    ): Site {
        $site = Site::factory()->create([
            'source_listing_id' => $listing?->id,
            'partner_id' => $partner?->id ?? $listing?->partner_id,
            'slug' => 'form-site',
        ]);

        $page = SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'enquiry',
            'data' => ['heading' => 'Ask us', 'form_type' => $type->value, 'channel' => $channel],
            'sort' => 0,
        ]);

        return $site;
    }

    private function restaurant(): Listing
    {
        return Listing::factory()->create([
            'type' => ListingType::Restaurant,
            'accepts_inquiries' => true,
            'partner_id' => Partner::create(['name' => 'Kapana', 'email' => 'owner@example.com'])->id,
        ]);
    }

    /** @return array<string, string> */
    private function contact(): array
    {
        return ['name' => 'Anna Weber', 'email' => 'anna@example.com', 'website' => ''];
    }

    public function test_a_contact_form_asks_for_nothing_but_a_message(): void
    {
        $site = $this->siteFor(EnquiryFormType::Contact, $this->restaurant());

        $this->post("/_sites/{$site->slug}/enquiry", [...$this->contact(), 'message' => 'Are you open on Sundays?'])
            ->assertRedirect();

        $inquiry = Inquiry::sole();

        $this->assertSame(InquiryKind::Booking, $inquiry->kind);
        $this->assertNull($inquiry->check_in);
        $this->assertSame('Are you open on Sundays?', $inquiry->message);
    }

    public function test_a_stay_request_keeps_two_real_dates(): void
    {
        $listing = Listing::factory()->create(['type' => ListingType::Accommodation, 'accepts_inquiries' => true]);
        $site = $this->siteFor(EnquiryFormType::StayRequest, $listing);
        $in = Carbon::today()->addMonth()->toDateString();
        $out = Carbon::today()->addMonth()->addDays(3)->toDateString();

        // One control on the page, two columns underneath — the confirmation
        // mails and the calendar are built on the dates, and free text would
        // lose both.
        $this->post("/_sites/{$site->slug}/enquiry", [...$this->contact(), 'check_in' => $in, 'check_out' => $out])
            ->assertRedirect();

        $inquiry = Inquiry::sole();

        $this->assertSame($in, $inquiry->check_in?->toDateString());
        $this->assertSame($out, $inquiry->check_out?->toDateString());
    }

    public function test_a_restaurant_order_is_priced_from_the_menu(): void
    {
        $restaurant = $this->restaurant();
        $steak = MenuItem::factory()->for($restaurant)->create(['name' => 'Oryx fillet', 'price' => 245.00]);
        $beer = MenuItem::factory()->for($restaurant)->create(['name' => 'Windhoek Lager', 'price' => 35.50]);
        $site = $this->siteFor(EnquiryFormType::RestaurantOrder, $restaurant);

        $this->post("/_sites/{$site->slug}/enquiry", [
            ...$this->contact(),
            'items' => [$steak->id => 2, $beer->id => 3],
        ])->assertRedirect();

        $inquiry = Inquiry::sole();

        $this->assertSame(InquiryKind::Order, $inquiry->kind);
        $this->assertEqualsWithDelta(2 * 245.00 + 3 * 35.50, (float) $inquiry->total_amount, 0.001);
        $this->assertSame(2, $inquiry->items()->count());
        $this->assertSame('Oryx fillet', $inquiry->items()->where('menu_item_id', $steak->id)->sole()->name);
    }

    public function test_a_shop_can_take_an_order_without_any_listing_at_all(): void
    {
        // The case the product catalogue was written for: a boutique that never
        // listed on the travel platform. Its site is built from the partner
        // record, and every enquiry to it used to be answered with a 404.
        $partner = Partner::create(['name' => 'Craft Shop', 'email' => 'shop@example.com']);
        $site = $this->siteFor(EnquiryFormType::ProductOrder, partner: $partner);

        $bowl = ShopProduct::create([
            'site_id' => $site->id, 'title' => 'Carved bowl', 'price' => 480.00,
            'status' => 'published', 'image_ids' => [],
        ]);

        $this->post("/_sites/{$site->slug}/enquiry", [
            ...$this->contact(),
            'items' => [$bowl->id => 2],
            'address' => '12 Independence Ave, Windhoek',
        ])->assertRedirect();

        $inquiry = Inquiry::sole();

        $this->assertNull($inquiry->listing_id);
        $this->assertSame($partner->id, $inquiry->partner_id);
        $this->assertSame('Craft Shop', $inquiry->sellerName());
        $this->assertSame('shop@example.com', $inquiry->sellerEmail());
        $this->assertEqualsWithDelta(960.00, (float) $inquiry->total_amount, 0.001);
        $this->assertStringContainsString('Independence Ave', (string) $inquiry->message);
    }

    public function test_a_product_with_only_a_price_in_words_cannot_be_ordered(): void
    {
        $partner = Partner::create(['name' => 'Craft Shop', 'email' => 'shop@example.com']);
        $site = $this->siteFor(EnquiryFormType::ProductOrder, partner: $partner);

        // "Call for price" is a real thing a shop says and shows in the
        // catalogue. It is not a number an order can be totalled from.
        $onRequest = ShopProduct::create([
            'site_id' => $site->id, 'title' => 'Commissioned piece', 'price' => null,
            'price_text' => 'Call for price', 'status' => 'published', 'image_ids' => [],
        ]);

        $this->post("/_sites/{$site->slug}/enquiry", [
            ...$this->contact(),
            'items' => [$onRequest->id => 1],
            'address' => '12 Independence Ave, Windhoek',
        ])->assertRedirect();

        $this->assertSame(0, Inquiry::count());
    }

    public function test_the_posted_type_cannot_override_the_one_the_site_offers(): void
    {
        $restaurant = $this->restaurant();
        MenuItem::factory()->for($restaurant)->create(['price' => 100.00]);
        $site = $this->siteFor(EnquiryFormType::TableReservation, $restaurant);

        // The hidden field records what the visitor was looking at. It is not
        // an instruction — the block decides, and a table booking still needs
        // its date and time whatever the payload claims.
        $this->post("/_sites/{$site->slug}/enquiry", [
            ...$this->contact(),
            'form_type' => EnquiryFormType::RestaurantOrder->value,
            'items' => [1 => 5],
        ])->assertRedirect();

        $this->assertSame(0, Inquiry::count());
    }

    public function test_a_whatsapp_form_offers_no_second_way_to_send(): void
    {
        $site = $this->siteFor(
            EnquiryFormType::Contact,
            $this->restaurant(),
            channel: EnquiryBlock::CHANNEL_WHATSAPP,
        );
        $site->update(['whatsapp' => '+264811234567']);

        // One channel, never both. The form used to render a submit button and
        // a WhatsApp button side by side, which asks the visitor to choose a
        // medium before they have said anything.
        $this->get($site->publicUrl())
            ->assertSee('Send via WhatsApp')
            ->assertDontSee('Send request');
    }
}
