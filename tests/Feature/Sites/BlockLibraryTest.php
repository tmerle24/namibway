<?php

namespace Tests\Feature\Sites;

use App\Enums\BusinessType;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\BlockRegistry;
use App\Sites\Rendering\SafeLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The library holds together: every layout names a real block, every block has
 * a view, and nothing writes a payload the block cannot render.
 *
 * The last one carries the weight. The payload is JSON, so the database cannot
 * check it — this is where the guarantee actually lives.
 */
class BlockLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_block_type_has_a_view(): void
    {
        foreach (BlockRegistry::all() as $type => $definition) {
            $this->assertTrue(
                View::exists($definition->view()),
                "The [{$type}] block has no view at {$definition->view()}."
            );
        }
    }

    public function test_every_layout_names_blocks_that_exist(): void
    {
        foreach (BusinessType::cases() as $type) {
            foreach (BlockRegistry::layoutFor($type) as $blockType) {
                $this->assertTrue(
                    BlockRegistry::has($blockType),
                    "The {$type->value} layout starts with a [{$blockType}] block, which is not in the library."
                );
            }
        }
    }

    public function test_every_layout_ends_in_a_footer_and_starts_with_a_hero(): void
    {
        foreach (BusinessType::cases() as $type) {
            $layout = BlockRegistry::layoutFor($type);

            $this->assertSame('hero', reset($layout));
            $this->assertSame('footer', end($layout));
        }
    }

    public function test_an_unknown_block_type_cannot_be_saved(): void
    {
        $site = Site::factory()->create();
        $page = SitePage::factory()->create(['site_id' => $site->id]);

        $this->expectException(InvalidArgumentException::class);

        SiteBlock::create(['site_page_id' => $page->id, 'type' => 'weather_widget', 'data' => []]);
    }

    public function test_a_payload_the_block_cannot_render_is_refused(): void
    {
        $site = Site::factory()->create();
        $page = SitePage::factory()->create(['site_id' => $site->id]);

        $this->expectException(InvalidArgumentException::class);

        // A highlight without a title is a bullet with nothing on it.
        SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'highlights',
            'data' => ['items' => [['text' => 'no title']]],
        ]);
    }

    /**
     * The kit's security model is that an owner cannot introduce code. A link
     * target is the one field where a typed string could still become code.
     */
    public function test_a_link_that_would_execute_is_defused(): void
    {
        $this->assertSame('#', SafeLink::href('javascript:alert(1)'));
        $this->assertSame('#', SafeLink::href('data:text/html;base64,PHNjcmlwdD4='));
        $this->assertSame('#', SafeLink::href('  JavaScript:alert(1)'));

        $this->assertSame('https://example.com.na', SafeLink::href('https://example.com.na'));
        $this->assertSame('https://example.com.na', SafeLink::href('example.com.na'));
        $this->assertSame('mailto:a@b.com', SafeLink::href('mailto:a@b.com'));
        $this->assertSame('#contact', SafeLink::href('#contact'));
    }

    public function test_a_whatsapp_number_becomes_a_link_or_nothing(): void
    {
        $this->assertSame('https://wa.me/264810000000', SafeLink::whatsapp('+264 81 000 0000'));
        $this->assertNull(SafeLink::whatsapp('ask at reception'));
        $this->assertNull(SafeLink::whatsapp(null));
    }
}
