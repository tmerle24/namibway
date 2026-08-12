<?php

namespace App\Filament\Support;

use App\Enums\ContentSource;
use App\Models\Listing;
use App\Models\Site;
use App\Models\SiteImage;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;

/**
 * The pictures a site may use.
 *
 * The blocks choose from these by id and never from the listing — that
 * independence is the whole of "you keep your content if you leave", and it is
 * why the bytes are copied into the site's own prefix rather than referenced.
 * Until now the only thing that could put a row here was generation, so a
 * business with no listing had an editor with an empty picture list and no way
 * to fill it. This is that way.
 *
 * Same shape as the other editors — one configure(), poured into a form action
 * for the tab and a page action for the owner's own screen.
 */
class EditSiteImagesAction
{
    public static function make(string $name = 'edit_images'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (?Listing $record): bool => $record !== null && self::siteFor($record) !== null);
    }

    public static function header(string $name = 'edit_images'): PageAction
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
            ->label('Pictures')
            ->icon('heroicon-o-photo')
            ->color('gray')
            ->modalHeading('The pictures this site can use')
            ->modalDescription('Upload them here, then choose them in a band under Content. '
                .'Only pictures the business owns or has the right to publish.')
            ->modalSubmitActionLabel('Save')
            ->modalWidth('4xl')
            ->fillForm(fn (?Listing $record): array => ['images' => self::state($record)])
            ->form(fn (?Listing $record): array => [
                Forms\Components\Repeater::make('images')
                    ->hiddenLabel()
                    ->addActionLabel('Add a picture')
                    ->reorderableWithButtons()
                    ->columns(2)
                    ->schema([
                        // Kept so a saved row is updated rather than replaced —
                        // a new row every time would leave the blocks pointing
                        // at ids that no longer exist.
                        Forms\Components\Hidden::make('id'),

                        Forms\Components\FileUpload::make('key')
                            ->label('Picture')
                            ->image()
                            ->disk('r2')
                            ->directory(fn (): string => self::prefixFor($record))
                            ->imageEditor()
                            ->openable()
                            // R2 answers metadata calls slowly enough to be felt
                            // in a modal, and nothing here needs them: the
                            // thumbnail route sizes on demand and never upscales.
                            ->fetchFileInformation(false)
                            ->required(),

                        Forms\Components\TextInput::make('alt')
                            ->label('What is in it')
                            ->maxLength(160)
                            ->helperText('Read aloud by a screen reader, and shown if the picture '
                                .'does not load. A short description, not a caption.'),
                    ]),
            ])
            ->action(function (?Listing $record, array $data): void {
                $site = $record === null ? null : self::siteFor($record);

                if ($site === null) {
                    return;
                }

                self::write($site, is_array($data['images'] ?? null) ? $data['images'] : []);
            });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function state(?Listing $record): array
    {
        $site = $record === null ? null : self::siteFor($record);

        if ($site === null) {
            return [];
        }

        return $site->images()->orderBy('sort')->get()
            ->map(fn (SiteImage $image): array => [
                'id' => $image->id,
                'key' => $image->key,
                'alt' => $image->alt,
            ])
            ->all();
    }

    /**
     * @param  array<int, mixed>  $state
     */
    private static function write(Site $site, array $state): void
    {
        $kept = [];
        $sort = 0;

        foreach ($state as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $key = self::key($entry['key'] ?? null);

            if ($key === null) {
                continue;
            }

            $rawAlt = $entry['alt'] ?? null;
            $alt = is_string($rawAlt) ? trim($rawAlt) : null;
            $existing = is_numeric($entry['id'] ?? null)
                ? $site->images()->whereKey((int) $entry['id'])->first()
                : null;

            if ($existing instanceof SiteImage) {
                $existing->update(['key' => $key, 'alt' => $alt ?: null, 'sort' => $sort]);
                $kept[] = $existing->id;
            } else {
                $kept[] = SiteImage::create([
                    'site_id' => $site->id,
                    'key' => $key,
                    'alt' => $alt ?: null,
                    // Somebody put this here on the business's behalf, which is
                    // the top of the content ladder and — unlike a Google
                    // Places photograph — ours to publish and theirs to keep.
                    'content_source' => ContentSource::Partner,
                    'prospect_only' => false,
                    'sort' => $sort,
                ])->id;
            }

            $sort++;
        }

        $removed = $site->images()->whereNotIn('id', $kept === [] ? [0] : $kept)->get();

        foreach ($removed as $image) {
            // The row goes and the object stays. Deleting bytes from a shared
            // bucket on a form submit is the kind of thing that is only ever
            // discovered later, and `photos:audit-r2` is what collects what
            // nothing references any more.
            $image->delete();
        }

        // A band pointing at a picture that has been taken away would render an
        // empty slot, so the references go with it.
        if ($removed->isNotEmpty()) {
            self::forgetImages($site, array_map('intval', $removed->pluck('id')->all()));
        }

        Notification::make()
            ->title('Saved')
            ->body(count($kept).' '.(count($kept) === 1 ? 'picture' : 'pictures').' on this site.')
            ->success()
            ->send();
    }

    /**
     * Take deleted pictures out of the blocks that referenced them.
     *
     * `image_id` and `image_ids` are the two shapes in the library (see
     * HeroBlock, AboutBlock, GalleryBlock). A block is saved through the model,
     * so it is validated on the way like any other write.
     *
     * @param  array<int, int>  $ids
     */
    private static function forgetImages(Site $site, array $ids): void
    {
        foreach ($site->pages as $page) {
            foreach ($page->blocks as $block) {
                $data = $block->data ?? [];
                $changed = false;

                if (isset($data['image_id']) && in_array((int) $data['image_id'], $ids, true)) {
                    $data['image_id'] = null;
                    $changed = true;
                }

                if (isset($data['image_ids']) && is_array($data['image_ids'])) {
                    $remaining = array_values(array_filter(
                        $data['image_ids'],
                        fn ($id): bool => ! in_array((int) $id, $ids, true)
                    ));

                    if ($remaining !== $data['image_ids']) {
                        $data['image_ids'] = $remaining;
                        $changed = true;
                    }
                }

                if ($changed) {
                    $block->update(['data' => $data]);
                }
            }
        }
    }

    /**
     * What the upload left behind: a key on the bucket.
     *
     * Filament hands back either a plain path or a keyed array of them,
     * depending on how the component was filled, so both are accepted.
     */
    private static function key(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function prefixFor(?Listing $record): string
    {
        $site = $record === null ? null : self::siteFor($record);

        return $site === null ? 'sites' : $site->mediaPrefix();
    }

    private static function siteFor(Listing $listing): ?Site
    {
        return Site::where('source_listing_id', $listing->id)->first();
    }
}
