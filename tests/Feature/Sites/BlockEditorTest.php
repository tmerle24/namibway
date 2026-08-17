<?php

namespace Tests\Feature\Sites;

use App\Filament\Support\EditBlocksAction;
use App\Filament\Support\Sites\BlockForm;
use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use App\Sites\BlockRegistry;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\RichEditor;
use Filament\Support\Exceptions\Halt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Editing what is on a customer's page.
 *
 * The load-bearing test here is the first one. The panel's fields live in
 * App\Filament\Support\Sites\BlockForm and the payload's shape lives in
 * App\Sites\Blocks, deliberately apart — domain code that serves the public
 * renderer has no business carrying Filament components. The cost of that split
 * is drift, and this is what pays it: every type in the registry has a form, and
 * every field on a form is a key its own type accepts.
 */
class BlockEditorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * `is_enabled` is a column on the block row, not part of its payload. It
     * rides inside the builder item so that switching a band off follows the
     * band when it is dragged, and is taken back out on the way in.
     */
    private const NOT_PAYLOAD = ['is_enabled'];

    /**
     * A page carries one band of each type: the row is found by type
     * (`firstOrNew`) and `write()` refuses a page with two of anything. The
     * picker has to say so, or half of what it offers on a filled page is a
     * choice that can only fail — and it fails at save, after the filling in.
     */
    public function test_the_picker_offers_each_band_once(): void
    {
        foreach (BlockForm::builderBlocks($this->site()) as $block) {
            $this->assertSame(
                1,
                $block->getMaxItems(),
                "The [{$block->getName()}] band may be added more than once, but a page carries one of each."
            );
        }
    }

    public function test_every_block_type_has_a_form_and_no_field_the_type_rejects(): void
    {
        $site = $this->site();

        foreach (BlockRegistry::all() as $type => $definition) {
            $fields = BlockForm::for($type, $site);

            $this->assertNotEmpty($fields, "The [{$type}] block has no form, so nobody can edit it.");

            $allowed = [];

            foreach (array_keys($definition->rules()) as $rule) {
                // 'items.*.title' is the same top-level key as 'items'.
                $allowed[] = explode('.', $rule)[0];
            }

            foreach ($fields as $field) {
                $name = $field instanceof Component ? $field->getName() : null;

                $this->assertNotNull($name);
                $this->assertContains(
                    $name,
                    array_merge($allowed, self::NOT_PAYLOAD),
                    "The [{$type}] form has a field [{$name}] that the block's own rules do not accept."
                );
            }
        }
    }

    /**
     * Only a key the definition names as rich text may reach a rich editor —
     * everything else is text the view escapes, and the two must not swap.
     */
    public function test_only_declared_rich_text_fields_get_a_rich_editor(): void
    {
        $site = $this->site();

        foreach (BlockRegistry::all() as $type => $definition) {
            foreach (BlockForm::for($type, $site) as $field) {
                if ($field instanceof RichEditor) {
                    $this->assertContains(
                        $field->getName(),
                        $definition->richTextFields(),
                        "The [{$type}] form edits [{$field->getName()}] as markup, but the block does not declare it as rich text."
                    );
                }
            }
        }
    }

    public function test_editing_writes_the_payload_the_order_and_the_switch(): void
    {
        $site = $this->site();
        $page = SitePage::factory()->create(['site_id' => $site->id, 'is_home' => true, 'slug' => '']);

        SiteBlock::create(['site_page_id' => $page->id, 'type' => 'about', 'data' => ['body' => 'Old words.'], 'sort' => 0]);
        SiteBlock::create(['site_page_id' => $page->id, 'type' => 'gallery', 'data' => ['heading' => 'Pictures'], 'sort' => 1]);

        $this->write($site, [
            ['type' => 'gallery', 'data' => ['heading' => 'Our place', 'is_enabled' => false]],
            ['type' => 'about', 'data' => ['heading' => 'About us', 'body' => 'New words.', 'is_enabled' => true]],
        ]);

        $gallery = $page->blocks()->where('type', 'gallery')->sole();
        $about = $page->blocks()->where('type', 'about')->sole();

        $this->assertSame(0, $gallery->sort);
        $this->assertFalse($gallery->is_enabled);
        $this->assertSame('Our place', $gallery->data['heading']);

        $this->assertSame(1, $about->sort);
        $this->assertSame('New words.', $about->data['body']);

        // The switch is a column, and must not have been written into the
        // payload — the block's own rules do not know it.
        $this->assertArrayNotHasKey('is_enabled', $about->data);
    }

    public function test_a_band_taken_off_the_page_is_removed(): void
    {
        $site = $this->site();
        $page = SitePage::factory()->create(['site_id' => $site->id, 'is_home' => true, 'slug' => '']);

        SiteBlock::create(['site_page_id' => $page->id, 'type' => 'about', 'data' => ['body' => 'Words.'], 'sort' => 0]);
        SiteBlock::create(['site_page_id' => $page->id, 'type' => 'cta', 'data' => ['heading' => 'Come'], 'sort' => 1]);

        $this->write($site, [['type' => 'about', 'data' => ['body' => 'Words.']]]);

        $this->assertSame(1, $page->blocks()->count());
        $this->assertSame('about', $page->blocks()->sole()->type);
    }

    /**
     * The renderer's views print these fields unescaped, and until there was an
     * editor the only way one could be filled was by copying a description that
     * had already been through the purifier. A person typing is the new case.
     */
    public function test_markup_a_person_pastes_is_purified_before_it_is_stored(): void
    {
        $site = $this->site();
        $page = SitePage::factory()->create(['site_id' => $site->id, 'is_home' => true, 'slug' => '']);

        $block = SiteBlock::create([
            'site_page_id' => $page->id,
            'type' => 'rich_text',
            'data' => ['body' => '<p>Fine.</p><script>alert(1)</script><a href="javascript:alert(2)">x</a>'],
            'sort' => 0,
        ]);

        $body = (string) $block->refresh()->data['body'];

        $this->assertStringContainsString('Fine.', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('javascript:', $body);
    }

    /**
     * Generation resolves a block by its type, so two of one kind on a page
     * would make a rebuild pick one of them arbitrarily.
     */
    public function test_two_bands_of_one_kind_are_refused(): void
    {
        $site = $this->site();
        SitePage::factory()->create(['site_id' => $site->id, 'is_home' => true, 'slug' => '']);

        $this->expectException(Halt::class);

        $this->write($site, [
            ['type' => 'about', 'data' => ['body' => 'One.']],
            ['type' => 'about', 'data' => ['body' => 'Two.']],
        ]);
    }

    private function site(): Site
    {
        $listing = Listing::factory()->create();

        return Site::factory()->create(['source_listing_id' => $listing->id]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $state
     */
    private function write(Site $site, array $state): void
    {
        $page = $site->pages()->where('is_home', true)->sole();

        $method = new ReflectionMethod(EditBlocksAction::class, 'write');
        $method->setAccessible(true);
        $method->invoke(null, $page, $state);
    }
}
