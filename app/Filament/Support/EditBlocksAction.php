<?php

namespace App\Filament\Support;

use App\Filament\Support\Sites\BlockForm;
use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Components\Builder\Block;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use InvalidArgumentException;

/**
 * The content of a website: its bands, their order, and what is in them.
 *
 * Everything else on the Website tab edits the frame — the opening screen, the
 * logo, the faces, the legal pages. This edits the site itself, which until now
 * nobody could: the blocks were written once by `sites:generate` and then could
 * not be touched, so a typo in generated prose was unfixable and a business with
 * no listing had an empty frame nothing could fill.
 *
 * Same shape as the other editors here — one configure(), poured into a form
 * action for the tab and a page action for the owner's own screen, so the
 * team's copy and the customer's cannot drift apart.
 *
 * ## One block of each type per page
 *
 * Enforced here rather than assumed, because generation resolves a block by its
 * type (`SiteGenerator::writeBlocks` → `firstOrNew(['type' => …])`). Two galleries
 * on a page would make a rebuild pick one of them arbitrarily, and "arbitrarily"
 * on somebody's live shopfront is not a thing to leave lying around. Refused
 * with the type named, rather than silently dropping the second.
 */
class EditBlocksAction
{
    public static function make(string $name = 'edit_blocks'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (?Listing $record): bool => $record !== null && self::siteFor($record) !== null);
    }

    public static function header(string $name = 'edit_blocks'): PageAction
    {
        return self::configure(PageAction::make($name))
            ->visible(fn (Listing $record): bool => self::siteFor($record) !== null);
    }

    /**
     * @template T of FormAction|PageAction
     *
     * @param  T  $action
     * @return T
     */
    private static function configure(FormAction|PageAction $action): FormAction|PageAction
    {
        return $action
            ->label('Content')
            ->icon('heroicon-o-squares-2x2')
            ->color('gray')
            ->modalHeading('What is on the page')
            ->modalDescription('Each band is a block. Drag to reorder, switch one off to keep its text '
                .'without showing it, and add what is missing.')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('5xl')
            ->fillForm(fn (?Listing $record): array => ['blocks' => self::state($record)])
            ->form(fn (?Listing $record): array => [
                Forms\Components\Builder::make('blocks')
                    ->hiddenLabel()
                    ->blocks(self::blocksFor($record))
                    ->collapsible()
                    ->collapsed()
                    ->cloneable(false)
                    ->blockNumbers(false)
                    ->addActionLabel('Add a band'),
            ])
            ->action(function (?Listing $record, array $data): void {
                $site = $record === null ? null : self::siteFor($record);

                if ($site === null) {
                    return;
                }

                self::write($site, is_array($data['blocks'] ?? null) ? $data['blocks'] : []);
            });
    }

    /**
     * @return array<int, Block>
     */
    private static function blocksFor(?Listing $record): array
    {
        $site = $record === null ? null : self::siteFor($record);

        return $site === null ? [] : BlockForm::builderBlocks($site);
    }

    /**
     * The page as the builder wants it: one entry per block, in stored order.
     *
     * `is_enabled` rides inside the payload here and is taken back out on the
     * way in — it is a property of the block row and not of its content, and a
     * separate control outside the builder could not follow a band being
     * dragged.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function state(?Listing $record): array
    {
        $site = $record === null ? null : self::siteFor($record);

        if ($site === null) {
            return [];
        }

        return self::page($site)->blocks()->orderBy('sort')->get()
            ->map(fn (SiteBlock $block): array => [
                'type' => $block->type,
                'data' => ($block->data ?? []) + ['is_enabled' => $block->is_enabled],
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $state
     */
    private static function write(Site $site, array $state): void
    {
        $page = self::page($site);
        $seen = [];
        $sort = 0;

        foreach ($state as $entry) {
            if (! is_array($entry) || ! is_string($entry['type'] ?? null)) {
                continue;
            }

            $type = $entry['type'];
            $payload = is_array($entry['data'] ?? null) ? $entry['data'] : [];

            if (in_array($type, $seen, true)) {
                Notification::make()
                    ->title('Not saved')
                    ->body('There are two "'.$type.'" bands on the page. A page carries one of each — '
                        .'put the second one\'s content into the first and remove it.')
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt;
            }

            $enabled = (bool) ($payload['is_enabled'] ?? true);
            unset($payload['is_enabled']);

            $block = $page->blocks()->firstOrNew(['type' => $type]);

            try {
                // The model validates and purifies on save — see SiteBlock. A
                // refusal here is a payload this type does not accept, which is
                // the editor's own bug and worth showing rather than swallowing.
                $block->fill(['data' => $payload, 'sort' => $sort, 'is_enabled' => $enabled])->save();
            } catch (InvalidArgumentException $refusal) {
                Notification::make()
                    ->title('Not saved')
                    ->body($refusal->getMessage())
                    ->danger()
                    ->persistent()
                    ->send();

                throw new Halt;
            }

            $seen[] = $type;
            $sort++;
        }

        // A band taken off the page goes, rather than lingering switched off:
        // switching off is its own control, and somebody who removed a block
        // meant to remove it.
        $page->blocks()->whereNotIn('type', $seen === [] ? [''] : $seen)->delete();

        Notification::make()
            ->title('Saved')
            ->body('The site shows it straight away.')
            ->success()
            ->send();
    }

    /**
     * The page being edited.
     *
     * The home page, and today that is the only one — `site_pages` exists so a
     * second page and a second language are inserts rather than migrations, but
     * nothing creates one yet. Created here with the same keys the generator
     * uses, so a site whose generation was interrupted is still editable.
     */
    private static function page(Site $site): SitePage
    {
        return $site->pages()->firstOrCreate(
            ['locale' => $site->default_locale, 'slug' => ''],
            ['is_home' => true, 'title' => $site->name, 'sort' => 0],
        );
    }

    private static function siteFor(Listing $listing): ?Site
    {
        return Site::where('source_listing_id', $listing->id)->first();
    }
}
