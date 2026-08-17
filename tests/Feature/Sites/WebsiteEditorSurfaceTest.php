<?php

namespace Tests\Feature\Sites;

use App\Models\ShopProduct;
use App\Models\SiteBlock;
use App\Models\SiteImage;
use App\Models\SitePage;
use Tests\TestCase;

/**
 * A website is edited in one place, reached from either owner.
 *
 * A `Site` hangs off a listing or off a partner, and everything on it — pages,
 * bands, pictures, products — hangs off the site. Once the site is resolved the
 * two owners are indistinguishable, so there is no honest reason for a listing's
 * website editor and a partner's website editor to be different screens. They
 * are one: `WebsiteTab`, assembled from shared action classes, mounted by both
 * resources.
 *
 * That is easy to say and easy to lose, and it has been lost twice in one week.
 * A global Content → Shop Products resource grew up beside the tab and then
 * drifted — it still offered `price` as free text with `maxLength(100)` after
 * the column had become a decimal, so the same product had two editors that
 * disagreed about what a price is. And a duplicate "Products" button sat in the
 * partner page header directly above the tab that already had one.
 *
 * Neither was a decision. Both were somebody adding a screen near the problem
 * they had that day. So the rule is held down here rather than remembered:
 *
 *   1. Whatever the Website tab offers is offered nowhere else in /admin.
 *   2. Nothing a site owns gets a Filament resource of its own.
 *   3. Both owners mount the same tab class.
 *
 * The list in rule 1 is not written out below — it is read out of `WebsiteTab`
 * itself, so an action added to the tab tomorrow is protected without anyone
 * having to remember this file exists.
 */
class WebsiteEditorSurfaceTest extends TestCase
{
    /**
     * The partner *panel* is the deliberate exception and is excluded below.
     * Its listing page has no tabs at all, so its header button is that panel's
     * one link to the editor — not a second way into the admin panel's.
     */
    private const ADMIN_PAGES = __DIR__.'/../../../app/Filament/Resources';

    public function test_the_website_tab_is_the_only_way_into_the_website_editor(): void
    {
        $actions = $this->actionsMountedByTheWebsiteTab();

        $this->assertNotEmpty($actions, 'Could not read any actions out of WebsiteTab — has it been restructured?');

        $offenders = [];

        foreach ($this->phpFilesIn(self::ADMIN_PAGES) as $file) {
            $source = (string) file_get_contents($file);

            foreach ($actions as $action) {
                if (str_contains($source, $action.'::')) {
                    $offenders[] = basename($file).' mounts '.$action;
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'A website action is mounted outside the Website tab:',
            ...$offenders,
            '',
            'The tab is the editor. A second button for the same thing drifts from the',
            'first one — put it in WebsiteTab, where both owners already get it.',
        ]));
    }

    public function test_nothing_a_site_owns_has_a_resource_of_its_own(): void
    {
        $owned = [
            SitePage::class,
            SiteBlock::class,
            SiteImage::class,
            ShopProduct::class,
        ];

        $offenders = [];

        foreach ($this->phpFilesIn(self::ADMIN_PAGES) as $file) {
            $source = (string) file_get_contents($file);

            if (! str_contains($source, 'protected static ?string $model')) {
                continue;
            }

            foreach ($owned as $model) {
                if (str_contains($source, $model.'::class')) {
                    $offenders[] = basename($file).' is a resource for '.class_basename($model);
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", [
            'A site-owned model has its own top-level resource:',
            ...$offenders,
            '',
            'These are edited through the Website tab on the listing or partner that',
            'owns the site. A resource far from that link is one nobody remembers to',
            'change when the columns move underneath it.',
        ]));
    }

    public function test_both_owners_mount_the_same_tab(): void
    {
        foreach (['ListingResource.php', 'PartnerResource.php'] as $resource) {
            $source = (string) file_get_contents(self::ADMIN_PAGES.'/'.$resource);

            $this->assertStringContainsString(
                'WebsiteTab::make()',
                $source,
                $resource.' must mount the shared WebsiteTab, not a website editor of its own.'
            );
        }
    }

    /**
     * The action classes WebsiteTab assembles, read from its source.
     *
     * @return list<string>
     */
    private function actionsMountedByTheWebsiteTab(): array
    {
        $source = (string) file_get_contents(__DIR__.'/../../../app/Filament/Support/WebsiteTab.php');

        preg_match_all('/\b(\w+Action)::make\(/', $source, $matches);

        // `Action::make(...)` is Filament's own generic action, used for the
        // inline build/publish/open buttons. It is not a class of ours.
        return array_values(array_unique(array_diff($matches[1], ['Action'])));
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
