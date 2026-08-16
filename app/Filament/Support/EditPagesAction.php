<?php

namespace App\Filament\Support;

use App\Models\Listing;
use App\Models\Partner;
use App\Models\Site;
use App\Models\SitePage;
use App\Sites\LegalText;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Str;

/**
 * The pages of a site.
 *
 * The renderer has always been able to serve more than one — `SiteController`
 * resolves a slug against `site_pages` and the route already carries `{page?}`
 * — but nothing could make a second one, so every site was a single scrolling
 * page whether or not that suited the business. A guest house is fine that way;
 * a tour operator with eight itineraries is not.
 *
 * Two rules are enforced here rather than left to care:
 *
 * - **The home page cannot be removed or renamed out of its slot.** It is what
 *   answers at the root of the domain, what the draft link points at, and what
 *   `sites:generate` writes into. Its title is editable; its slug is not.
 * - **A slug the renderer already answers is refused.** Privacy and the legal
 *   notice are rendered from the site record rather than from a page (see
 *   LegalText), and `robots.txt` and `sitemap.xml` are answered before any page
 *   is looked up — so a page called any of those would be created, saved, and
 *   then never appear, which is worse than being told no.
 */
class EditPagesAction
{
    /**
     * Slugs the renderer answers itself. A page carrying one would be
     * unreachable, silently.
     *
     * @var array<int, string>
     */
    private const RESERVED = ['robots.txt', 'sitemap.xml', 'enquiry'];

    public static function make(string $name = 'edit_pages'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null);
    }

    public static function header(string $name = 'edit_pages'): PageAction
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
            ->label('Pages')
            ->icon('heroicon-o-document-duplicate')
            ->color('gray')
            ->modalHeading('The pages of this site')
            ->modalDescription('One page is enough for most businesses. Add more when there is more to '
                .'say than a visitor will scroll through — then fill each one under Content.')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('3xl')
            ->fillForm(fn (Listing|Partner|null $record): array => ['pages' => self::state($record)])
            ->form([
                Forms\Components\Repeater::make('pages')
                    ->hiddenLabel()
                    ->addActionLabel('Add a page')
                    ->reorderableWithButtons()
                    ->columns(2)
                    ->itemLabel(fn (array $state): ?string => is_string($state['title'] ?? null)
                        ? $state['title']
                        : null)
                    ->deletable(fn (array $state): bool => ($state['is_home'] ?? false) !== true)
                    ->schema([
                        Forms\Components\Hidden::make('id'),
                        Forms\Components\Hidden::make('is_home'),

                        Forms\Components\TextInput::make('title')
                            ->label('Title')
                            ->required()
                            ->maxLength(120)
                            ->helperText('What the menu calls it.'),

                        Forms\Components\TextInput::make('slug')
                            ->label('Address')
                            ->maxLength(60)
                            ->prefix('/')
                            // The home page is the root. Nothing good comes of
                            // letting somebody move it.
                            ->disabled(fn (Forms\Get $get): bool => $get('is_home') === true)
                            ->placeholder(fn (Forms\Get $get): string => $get('is_home') === true
                                ? 'the front page'
                                : 'made from the title if left empty'),
                    ]),
            ])
            ->action(function (Listing|Partner|null $record, array $data): void {
                $site = $record === null ? null : SiteResolver::for($record);

                if ($site === null) {
                    return;
                }

                self::write($site, is_array($data['pages'] ?? null) ? $data['pages'] : []);
            });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function state(Listing|Partner|null $record): array
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
            ->map(fn (SitePage $page): array => [
                'id' => $page->id,
                'is_home' => $page->is_home,
                'title' => $page->title ?? '',
                'slug' => $page->slug,
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $state
     */
    private static function write(Site $site, array $state): void
    {
        $kept = [];
        $slugs = [];
        $sort = 0;

        foreach ($state as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $existing = is_numeric($entry['id'] ?? null)
                ? $site->pages()->whereKey((int) $entry['id'])->first()
                : null;

            $isHome = $existing instanceof SitePage ? $existing->is_home : false;
            $title = is_string($entry['title'] ?? null) ? trim($entry['title']) : '';

            if ($title === '') {
                continue;
            }

            $slug = $isHome ? '' : self::slug($entry['slug'] ?? null, $title);

            if (! $isHome) {
                self::assertUsable($slug, self::typed($entry['slug'] ?? null), $slugs);
                $slugs[] = $slug;
            }

            if ($existing instanceof SitePage) {
                $existing->update(['title' => $title, 'slug' => $slug, 'sort' => $sort]);
                $kept[] = $existing->id;
            } else {
                $kept[] = SitePage::create([
                    'site_id' => $site->id,
                    'locale' => $site->default_locale,
                    'title' => $title,
                    'slug' => $slug,
                    'is_home' => false,
                    'sort' => $sort,
                ])->id;
            }

            $sort++;
        }

        // A page removed takes its blocks with it — they are its content and
        // belong nowhere else. The home page is never in this set: the form
        // cannot delete it, and a state that somehow omitted it would otherwise
        // take the site's front page away.
        $site->pages()
            ->where('is_home', false)
            ->whereNotIn('id', $kept === [] ? [0] : $kept)
            ->each(function (SitePage $page): void {
                $page->blocks()->delete();
                $page->delete();
            });

        Notification::make()
            ->title('Saved')
            ->body(count($kept).' '.(count($kept) === 1 ? 'page' : 'pages').' on this site.')
            ->success()
            ->send();
    }

    private static function slug(mixed $given, string $title): string
    {
        $slug = is_string($given) ? Str::slug(trim($given)) : '';

        return $slug !== '' ? $slug : Str::slug($title);
    }

    /** The address exactly as it was typed, for the reserved-name check below. */
    private static function typed(mixed $given): string
    {
        return is_string($given) ? strtolower(trim($given)) : '';
    }

    /**
     * The slug alone is not enough to judge by. `Str::slug` turns `robots.txt`
     * into `robots-txt`, which collides with nothing — but somebody who typed
     * `robots.txt` meant the file, and quietly giving them a page at a
     * different address is the kind of helpfulness nobody thanks you for. So
     * both the slug and what was typed are checked.
     *
     * @param  array<int, string>  $taken
     */
    private static function assertUsable(string $slug, string $typed, array $taken): void
    {
        $refusal = match (true) {
            $slug === '' => 'A page needs an address. Give it a title with letters in it, or type one.',
            in_array($slug, self::RESERVED, true),
            in_array($typed, self::RESERVED, true) => '"'.($typed !== '' ? $typed : $slug).'" is answered '
                .'by the site itself, so a page there would never be seen. Pick another address.',
            LegalText::isLegalPage($slug) => '"'.$slug.'" is one of the legal pages, which are written '
                .'under Legal pages rather than as a page here.',
            in_array($slug, $taken, true) => 'Two pages cannot share the address "/'.$slug.'".',
            default => null,
        };

        if ($refusal === null) {
            return;
        }

        Notification::make()->title('Not saved')->body($refusal)->danger()->persistent()->send();

        throw new Halt;
    }
}
