<?php

namespace Tests\Feature\Filament;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * A wide table on a phone must scroll, not be cut off.
 *
 * This is a source scan rather than a rendered assertion on purpose: the bug
 * it guards is invisible to anything that reads the DOM. Nothing overflowed,
 * no page scrolled sideways, no element was missing — the right-hand columns
 * were simply clipped by an ancestor, so the drawer's payment lines lost State
 * and the Select button and the unpaid list lost Outstanding, which is the one
 * number that page exists to show. It looked like a table with fewer columns.
 * Found by taking a screenshot at 375px, which is not something CI does.
 *
 * So what is held here is the rule, at the only place it can be checked
 * cheaply: every `.nw-table` sits inside a `.nw-scroll`. The wrapper's own
 * behaviour — scrolling on screen, visible on paper so the arrivals board
 * still prints whole — lives in lodge-styles.blade.php and is asserted below.
 */
class NarrowScreenTablesTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function partnerViews(): array
    {
        $files = [];

        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(resource_path('views/filament/partner')),
        );

        foreach ($tree as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files, 'No partner views found — the walk is wrong, not the markup.');

        return $files;
    }

    public function test_every_wide_table_sits_in_a_scroll_wrapper(): void
    {
        foreach ($this->partnerViews() as $file) {
            $lines = explode("\n", (string) file_get_contents($file));

            foreach ($lines as $index => $line) {
                if (! str_contains($line, '<table class="nw-table')) {
                    continue;
                }

                $previous = trim($lines[$index - 1] ?? '');

                $this->assertStringContainsString(
                    'nw-scroll',
                    $previous,
                    basename($file).' line '.($index + 1).': a .nw-table without a .nw-scroll wrapper. '
                    .'On a phone its right-hand columns are silently cut off — no overflow, no scrollbar, just missing money.',
                );
            }
        }
    }

    public function test_the_wrapper_scrolls_on_screen_and_prints_whole(): void
    {
        $css = (string) file_get_contents(
            resource_path('views/filament/partner/partials/lodge-styles.blade.php'),
        );

        $this->assertStringContainsString('overflow-x: auto', $css);

        // On paper there is no scrollbar to drag, so a scroll box would print
        // one screenful and lose the rest — the same bug, moved onto the
        // arrivals board a lodge carries around the property.
        $print = strstr($css, '@media print');
        $this->assertIsString($print);
        $this->assertMatchesRegularExpression(
            '/\.nw-scroll\s*\{\s*overflow:\s*visible;/',
            $print,
        );
    }
}
