<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteBlock;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;

/**
 * The first screen: the town, the line in the largest type, and the paragraph
 * under it.
 *
 * This is the part of a generated site most likely to be wrong, because it is
 * the part we had to invent. The opening line comes from the category rather
 * than from anything the business told us (see App\Sites\HeroLines), so it is
 * the first thing anybody should be able to replace — and the town is a
 * separate switch, since plenty of businesses would rather not lead with it.
 *
 * Same shape as the other two editors here: one configure(), poured into a
 * table-panel action or a page-header one, so the team's copy and the owner's
 * cannot drift apart.
 */
class EditHeroAction
{
    public static function make(string $name = 'edit_hero'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (?Listing $record): bool => $record !== null && self::heroFor($record) !== null);
    }

    public static function header(string $name = 'edit_hero'): PageAction
    {
        return self::configure(PageAction::make($name))
            ->visible(fn (Listing $record): bool => self::heroFor($record) !== null);
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
            ->label('Opening screen')
            ->icon('heroicon-o-photo')
            ->color('gray')
            ->modalHeading('The first thing a visitor reads')
            ->modalDescription('The big line is ours, not the business\'s — we pick one that suits the '
                .'category, because a slogan is theirs to write. Replace it with anything better.')
            ->modalSubmitActionLabel('Save')
            ->fillForm(function (?Listing $record): array {
                $hero = $record === null ? null : self::heroFor($record);

                if ($hero === null) {
                    return [];
                }

                $data = $hero->data ?? [];

                return [
                    'show_town' => filled($data['eyebrow'] ?? null),
                    // $record cannot be null here: a null one produces a null
                    // hero, and that returned above.
                    'eyebrow' => $data['eyebrow'] ?? $record->city?->name,
                    'headline' => $data['headline'] ?? null,
                    'subline' => $data['subline'] ?? null,
                ];
            })
            ->form([
                Forms\Components\TextInput::make('headline')
                    ->label('Headline')
                    ->helperText('Set in the largest type on the page. Deliberately not the business\'s '
                        .'name — that is already in the bar above it, and saying it twice on one screen '
                        .'reads as a fault.')
                    ->maxLength(120)
                    ->required(),

                Forms\Components\Textarea::make('subline')
                    ->label('Under the headline')
                    ->rows(3)
                    ->maxLength(240),

                Forms\Components\Toggle::make('show_town')
                    ->label('Show the town above the headline')
                    ->live(),

                Forms\Components\TextInput::make('eyebrow')
                    ->label('Town')
                    ->maxLength(60)
                    ->visible(fn (Forms\Get $get): bool => (bool) $get('show_town')),
            ])
            ->action(function (?Listing $record, array $data): void {
                $hero = $record === null ? null : self::heroFor($record);

                if ($hero === null) {
                    return;
                }

                $payload = $hero->data ?? [];
                $payload['headline'] = $data['headline'] ?? null;
                $payload['subline'] = $data['subline'] ?? null;
                // Blank rather than absent: the block validates its own payload,
                // and the renderer already treats an empty eyebrow as no town.
                $payload['eyebrow'] = ($data['show_town'] ?? false) ? ($data['eyebrow'] ?? null) : null;

                $hero->data = $payload;
                $hero->save();

                Notification::make()->title('Saved')->success()->send();
            });
    }

    private static function heroFor(Listing $listing): ?SiteBlock
    {
        $site = Site::where('source_listing_id', $listing->id)->first();

        if ($site === null) {
            return null;
        }

        return SiteBlock::whereIn('site_page_id', $site->pages()->select('id'))
            ->where('type', 'hero')
            ->first();
    }
}
