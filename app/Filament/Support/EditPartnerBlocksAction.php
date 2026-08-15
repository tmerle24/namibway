<?php

namespace App\Filament\Support;

use App\Filament\Support\Sites\BlockForm;
use App\Models\Partner;
use App\Models\Site;
use App\Models\SiteBlock;
use App\Models\SitePage;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Components\Builder\Block;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use InvalidArgumentException;

/**
 * The block editor for partner websites — same concept as EditBlocksAction but
 * operating on a Site found via partner_id rather than source_listing_id.
 *
 * Only shown when the partner already has a generated website; invisible before
 * CreateWebsiteFromPartnerAction has been used.
 */
class EditPartnerBlocksAction
{
    public static function make(string $name = 'edit_partner_blocks'): Action
    {
        return Action::make($name)
            ->label('Content')
            ->icon('heroicon-o-squares-2x2')
            ->color('gray')
            ->visible(fn (Partner $record): bool => self::siteFor($record) !== null)
            ->modalHeading('What is on the page')
            ->modalDescription('Each band is a block. Drag to reorder, switch one off to keep its text '
                .'without showing it, and add what is missing.')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('5xl')
            ->fillForm(function (Partner $record): array {
                $site = self::siteFor($record);

                if ($site === null) {
                    return ['page_id' => null, 'blocks' => []];
                }

                $page = self::page($site);

                return ['page_id' => $page->id, 'blocks' => self::stateFor($page)];
            })
            ->form(fn (Partner $record): array => [
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
                    ->blocks(fn (): array => self::blocksFor($record))
                    ->collapsible()
                    ->collapsed()
                    ->cloneable(false)
                    ->blockNumbers(false)
                    ->addActionLabel('Add a section'),
            ])
            ->action(function (Partner $record, array $data): void {
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
    private static function blocksFor(Partner $record): array
    {
        $site = self::siteFor($record);

        return $site === null ? [] : BlockForm::builderBlocks($site);
    }

    /**
     * @return array<int, string>
     */
    private static function pageOptions(Partner $record): array
    {
        $site = self::siteFor($record);

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

    private static function pageById(Partner $record, ?int $id): ?SitePage
    {
        $site = self::siteFor($record);

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
     * @return array<int, array<string, mixed>>
     */
    private static function stateFor(SitePage $page): array
    {
        return $page->blocks()->orderBy('sort')->get()
            ->map(fn (SiteBlock $block): array => [
                'type' => $block->type,
                'data' => ($block->data ?? []) + ['is_enabled' => $block->is_enabled],
            ])
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

        $page->blocks()->whereNotIn('type', $seen === [] ? [''] : $seen)->delete();

        Notification::make()
            ->title('Saved')
            ->body('The site shows it straight away.')
            ->success()
            ->send();
    }

    private static function page(Site $site): SitePage
    {
        return $site->pages()->firstOrCreate(
            ['locale' => $site->default_locale, 'slug' => ''],
            ['is_home' => true, 'title' => $site->name, 'sort' => 0],
        );
    }

    private static function siteFor(Partner $partner): ?Site
    {
        return Site::where('partner_id', $partner->id)
            ->whereNull('source_listing_id')
            ->first();
    }
}
