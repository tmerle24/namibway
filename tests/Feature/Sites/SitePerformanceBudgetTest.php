<?php

namespace Tests\Feature\Sites;

use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\BlockRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The performance budget, checked rather than asserted.
 *
 * The flyer promises a page that loads on an old phone and a slow connection,
 * and a page that stays white for three seconds reads as broken rather than as
 * cheap. So the first view has a number on it — see config/sites.php — and this
 * is what makes a block that blows it fail CI instead of being discovered by a
 * customer six months in.
 *
 * The check is deliberately made against a page carrying **every** block type
 * at once, populated, which is worse than any real site. If the whole library
 * fits, one customer's selection of it fits.
 */
class SitePerformanceBudgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_page_carrying_every_block_stays_inside_the_budget(): void
    {
        $site = Site::factory()->published()->create([
            'name' => 'Every Block Lodge',
            'address' => "Erf 42\nSwakopmund",
            'contact_phone' => '+264 64 400 000',
            'contact_email' => 'stay@example.com',
            'whatsapp' => '+264 81 000 0000',
        ]);

        $page = SitePage::factory()->create(['site_id' => $site->id, 'title' => $site->name]);

        $sort = 0;

        foreach (BlockRegistry::all() as $type => $definition) {
            SiteBlock::create([
                'site_page_id' => $page->id,
                'type' => $type,
                'data' => $this->payloadFor($type) + $definition->defaults(),
                'sort' => $sort++,
            ]);
        }

        $html = $this->get('/_sites/'.$site->slug)->assertOk()->getContent();
        $budget = (int) config('sites.budget.document_bytes');

        $this->assertLessThan(
            $budget,
            strlen($html),
            'The first view is over its budget. Either the block that grew has to shrink, '
            .'or somebody has to decide the number in config/sites.php is wrong.'
        );
    }

    public function test_the_page_makes_no_request_to_a_third_party(): void
    {
        $site = Site::factory()->published()->create();
        $page = SitePage::factory()->create(['site_id' => $site->id]);

        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'hero',
            'data' => ['headline' => 'No third parties here'],
            'sort' => 0,
        ]);

        $html = $this->get('/_sites/'.$site->slug)->assertOk()->getContent();

        // A font host, an analytics tag or a map library is a DNS lookup and a
        // TLS handshake before anything can paint — on the connection this page
        // is sold against, each one costs more than the whole document.
        foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net', 'unpkg.com', 'googletagmanager.com'] as $host) {
            $this->assertStringNotContainsString($host, $html);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFor(string $type): array
    {
        $lorem = 'Sand, silence and a very long driveway. '
            .'We have been doing this since 1994 and we still get up early for it.';

        return match ($type) {
            'hero' => ['eyebrow' => 'Swakopmund', 'headline' => 'The quiet edge of the desert', 'subline' => $lorem],
            'about' => ['heading' => 'About us', 'body' => '<p>'.$lorem.'</p><p>'.$lorem.'</p>'],
            'highlights' => ['heading' => 'What we offer', 'items' => array_fill(0, 6, ['title' => 'Guided dune walks', 'text' => $lorem])],
            'rich_text' => ['heading' => 'Before you come', 'body' => '<p>'.$lorem.'</p>'],
            'mission' => ['heading' => 'Our Mission', 'body' => '<p>'.$lorem.'</p>'],
            'why_choose_us' => ['heading' => 'Why Choose Us?', 'body' => '<p>'.$lorem.'</p>'],
            'shop' => ['heading' => 'Shop'],
            'opening_hours' => ['heading' => 'Opening hours', 'days' => array_fill(0, 7, ['day' => 'Monday', 'hours' => '08:00–17:00'])],
            'price_list' => ['heading' => 'Prices', 'items' => array_fill(0, 20, ['name' => 'Double room, per night', 'description' => 'Breakfast included', 'price' => 'N$ 1 850'])],
            'location' => ['heading' => 'Find us', 'directions_note' => $lorem],
            'contact' => ['heading' => 'Get in touch', 'intro' => $lorem],
            'cta' => ['heading' => 'Come and see', 'text' => $lorem, 'label' => 'Send a message', 'href' => '#contact'],
            'footer' => ['legal_name' => 'Every Block Lodge (Pty) Ltd', 'registration' => 'Reg 2019/1234', 'links' => array_fill(0, 3, ['label' => 'Privacy', 'href' => '/privacy'])],
            default => [],
        };
    }
}
