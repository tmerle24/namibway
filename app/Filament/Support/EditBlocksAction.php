<?php

namespace App\Filament\Support;

use App\Filament\Support\Sites\BlockForm;
use App\Models\Listing;
use App\Models\Partner;
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
            ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null);
    }

    public static function header(string $name = 'edit_blocks'): PageAction
    {
        return self::configure(PageAction::make($name))
            ->visible(fn (Listing $record): bool => SiteResolver::for($record) !== null);
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
            ->fillForm(function (Listing|Partner|null $record): array {
                $site = $record === null ? null : SiteResolver::for($record);

                if ($site === null) {
                    return ['page_id' => null, 'blocks' => []];
                }

                $page = self::page($site);

                return ['page_id' => $page->id, 'blocks' => self::stateFor($page)];
            })
            ->form(fn (Listing|Partner|null $record): array => [
                // Which page is being edited. One site had one page until pages
                // could be created; now the editor has to say which, and
                // switching reloads the bands under it rather than opening a
                // second modal.
                Forms\Components\Select::make('page_id')
                    ->label('Page')
                    ->options(fn (): array => self::pageOptions($record))
                    ->native(false)
                    ->selectablePlaceholder(false)
                    ->live()
                    ->visible(fn (): bool => count(self::pageOptions($record)) > 1)
                    ->afterStateUpdated(function ($state, Forms\Set $set) use ($record): void {
                        $page = self::pageById($record, is_numeric($state) ? (int) $state : null);

                        $set('blocks', $page === null ? [] : self::stateFor($page));
                    }),

                Forms\Components\Builder::make('blocks')
                    ->hiddenLabel()
                    ->blocks(self::blocksFor($record))
                    ->collapsible()
                    ->collapsed()
                    ->cloneable(false)
                    ->blockNumbers(false)
                    ->addActionLabel('Add a band'),
            ])
            ->action(function (Listing|Partner|null $record, array $data): void {
                $page = self::pageById($record, is_numeric($data['page_id'] ?? null) ? (int) $data['page_id'] : null);

                if ($page === null) {
                    return;
                }

                self::write($page, is_array($data['blocks'] ?? null) ? $data['blocks'] : []);
            });
    }

    /**
     * @return array<int, Block>
     */
    private static function blocksFor(Listing|Partner|null $record): array
    {
        $site = $record === null ? null : SiteResolver::for($record);

        return $site === null ? [] : BlockForm::builderBlocks($site);
    }

    /**
     * The pages this site has, for the picker. Keyed by id and labelled the way
     * the menu labels them.
     *
     * @return array<int, string>
     */
    private static function pageOptions(Listing|Partner|null $record): array
    {
        $site = $record === null ? null : SiteResolver::for($record);

        if ($site === null) {
            return [];
        }

        return $site->pages()
            ->where('locale', $site->default_locale)
            ->orderByDesc('is_home')
            ->orderBy('sort')
            ->get()
            ->mapWithKeys(fn (SitePage $page): array => [
                $page->id => ($page->title ?: 'Untitled').($page->is_home ? ' (front page)' : ' — /'.$page->slug),
            ])
            ->all();
    }

    /**
     * A page of this site by id, and never anybody else's — the id travels
     * through a form field, so it is checked against the site rather than
     * trusted.
     */
    private static function pageById(Listing|Partner|null $record, ?int $id): ?SitePage
    {
        $site = $record === null ? null : SiteResolver::for($record);

        if ($site === null) {
            return null;
        }

        if ($id === null) {
            return self::page($site);
        }

        $page = $site->pages()->whereKey($id)->first();

        return $page instanceof SitePage ? $page : self::page($site);
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
    private static function stateFor(SitePage $page): array
    {
        return $page->blocks()->orderBy('sort')->get()
            ->map(function (SiteBlock $block): array {
                // A block created before a new field was added to its definition
                // has no value for that field. Without the defaults here, Filament
                // fills the form with null — which renders a Toggle as OFF, and
                // saving then writes false. The user never touched the toggle, but
                // the next save silently disables the feature for this site. Merging
                // the definition's defaults means a Toggle the user never touched
                // stays at its designed default rather than falling to OFF.
                //
                // The block's own values take precedence (left side of +), so a
                // toggle the user did set to false stays false.
                $defaults = $block->definition()?->defaults() ?? [];

                return [
                    'type' => $block->type,
                    'data' => ($block->data ?? []) + $defaults + ['is_enabled' => $block->is_enabled],
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, mixed>  $state
     */
    private static function write(SitePage $page, array $state): void
    {
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
}
