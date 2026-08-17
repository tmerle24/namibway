<?php

namespace Tests\Feature\Sites;

use App\Enums\InquiryKind;
use App\Enums\ShopProductStatus;
use App\Models\Inquiry;
use App\Models\Partner;
use App\Models\ShopProduct;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The shop index and product detail pages.
 *
 * The load-bearing tests here are the nav ones. Before the fix, both pages
 * rendered a stripped-down header with only a logo and a "← Back" link —
 * the full site navigation was present on the home page but absent from every
 * other URL the shop produced, making the site feel like two disconnected
 * pages. The sub-bar (breadcrumb + back button) is the secondary check: once
 * the full nav is there, the wayfinding inside the shop can be asserted too.
 */
class ShopPageTest extends TestCase
{
    use RefreshDatabase;

    private function siteWithShop(): array
    {
        $partner = Partner::create(['name' => 'Beads & Baskets', 'email' => 'shop@beads.na']);
        $site = Site::factory()->create(['name' => 'Beads & Baskets', 'partner_id' => $partner->id]);
        $page = SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'about',
            'data' => ['heading' => 'Who we are', 'body' => 'A workshop.'],
            'sort' => 0,
        ]);

        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'shop',
            'data' => ['heading' => 'Our collection'],
            'sort' => 1,
        ]);

        $product = ShopProduct::create([
            'site_id' => $site->id,
            'title' => 'Palm Basket',
            'slug' => 'palm-basket',
            'status' => ShopProductStatus::Published,
            'image_ids' => [],
            'sort' => 0,
        ]);

        return [$site, $product];
    }

    /**
     * Before the fix, /shop rendered a bare header with only a logo and
     * "← Back". The full nav partial was not included, so "Who we are" and
     * every other home page section were unreachable from the shop.
     */
    public function test_the_shop_index_carries_the_site_navigation(): void
    {
        [$site] = $this->siteWithShop();

        $this->get($site->pageUrl('shop'))
            ->assertOk()
            ->assertSee('Who we are')
            ->assertSee('id="nav"', false);
    }

    /**
     * The shop index breadcrumb orients the visitor: site name (home) › Shop.
     */
    public function test_the_shop_index_has_a_breadcrumb(): void
    {
        [$site] = $this->siteWithShop();

        $this->get($site->pageUrl('shop'))
            ->assertOk()
            ->assertSee('shop-breadcrumb', false)
            ->assertSee('Beads & Baskets')
            ->assertSee('aria-current="page"', false);
    }

    /**
     * The product detail page must carry the full site nav — same rule as the
     * index, for the same reason.
     */
    public function test_the_product_detail_page_carries_the_site_navigation(): void
    {
        [$site] = $this->siteWithShop();

        $this->get($site->pageUrl('shop/palm-basket'))
            ->assertOk()
            ->assertSee('Who we are')
            ->assertSee('id="nav"', false);
    }

    /**
     * Breadcrumb: site name › Shop › Product title. Back button points at /shop.
     */
    public function test_the_product_detail_page_has_breadcrumb_and_back_button(): void
    {
        [$site] = $this->siteWithShop();

        $html = $this->get($site->pageUrl('shop/palm-basket'))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);

        // Breadcrumb trail
        $this->assertStringContainsString('shop-breadcrumb', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('Palm Basket', $html);

        // Back link points at the shop index, not the site root
        $this->assertStringContainsString('shop-back', $html);
        $shopUrl = $site->pageUrl('shop');
        $this->assertStringContainsString('href="'.$shopUrl.'"', $html);
    }

    /**
     * On a wide screen, the WhatsApp button gives way to a typed enquiry — the
     * message medium should match the device, not be forced on a desktop user.
     * On mobile, only WhatsApp is shown; the desktop button is hidden by CSS.
     */
    public function test_whatsapp_is_mobile_only_and_email_is_desktop_only(): void
    {
        [$site, $product] = $this->siteWithShop();
        $site->update(['whatsapp' => '+264811234567', 'contact_email' => 'hi@beads.na']);

        $html = $this->get($site->pageUrl('shop/palm-basket'))
            ->assertOk()
            ->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('btn--whatsapp', $html);
        $this->assertStringContainsString('btn--desktop-only', $html);
    }

    /**
     * Posting the product enquiry form creates an Inquiry with kind=order,
     * with the product title in the message, and redirects back with sent=1.
     */
    public function test_product_enquiry_creates_an_order_inquiry(): void
    {
        Mail::fake();

        [$site, $product] = $this->siteWithShop();

        $this->post(route('sites.enquiry', $site), [
            'product_id' => $product->id,
            'name' => 'Anna Meyer',
            'email' => 'anna@example.com',
            'message' => 'Do you ship to Swakopmund?',
        ])->assertRedirect();

        $inquiry = Inquiry::latest()->first();
        $this->assertNotNull($inquiry);
        $this->assertSame(InquiryKind::Order, $inquiry->kind);
        $this->assertStringContainsString('Palm Basket', $inquiry->message);
        $this->assertStringContainsString('Swakopmund', $inquiry->message);
        $this->assertSame($site->partner_id, $inquiry->partner_id);
    }

    /**
     * A product_id that does not belong to this site is rejected silently.
     */
    public function test_product_enquiry_rejects_a_foreign_product_id(): void
    {
        Mail::fake();

        [$site] = $this->siteWithShop();
        $otherPartner = Partner::create(['name' => 'Other', 'email' => 'other@example.com']);
        $otherSite = Site::factory()->create(['partner_id' => $otherPartner->id]);
        $foreign = ShopProduct::create([
            'site_id' => $otherSite->id,
            'title' => 'Other', 'slug' => 'other',
            'status' => ShopProductStatus::Published,
            'image_ids' => [], 'sort' => 0,
        ]);

        $this->post(route('sites.enquiry', $site), [
            'product_id' => $foreign->id,
            'name' => 'Bot', 'email' => 'bot@example.com',
        ])->assertRedirect();

        $this->assertDatabaseCount('inquiries', 0);
    }
}
