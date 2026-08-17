<?php

namespace App\Filament\Support;

use App\Enums\ContentSource;
use App\Enums\ShopProductStatus;
use App\Jobs\ImportInstagramProductsJob;
use App\Jobs\ImportProductsFromFileJob;
use App\Models\Listing;
use App\Models\Partner;
use App\Models\ShopProduct;
use App\Models\Site;
use App\Models\SiteImage;
use App\Services\ShopProductDescriber;
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * Manage shop products for a listing's or partner's website.
 *
 * **This is the only place products are managed.** They belong to a site, and a
 * site belongs to a listing or a partner, so they are edited where that link is
 * — the Website tab — and nowhere else. There was briefly a second surface, a
 * global Content → Shop Products resource, and it demonstrated exactly why one
 * is the right number: when `shop_products.price` became a decimal column, the
 * resource kept offering it as free text with `maxLength(100)`, so the same
 * product had two editors that disagreed about what a price is. A screen far
 * from the thing it edits is a screen nobody remembers to change.
 *
 * Follows the same two-variant pattern as EditSiteImagesAction — a FormAction
 * for the admin WebsiteTab (mounted on both ListingResource and PartnerResource)
 * and a PageAction for the partner panel's listing header, which has no tabs.
 * Both resolve the site through SiteResolver.
 *
 * Import actions (Instagram, Excel/CSV) dispatch background jobs and notify
 * via the database bell; the repeater is for direct manual entry.
 */
class ManageShopProductsAction
{
    public static function make(string $name = 'manage_products'): FormAction
    {
        return self::configure(FormAction::make($name))
            ->visible(fn (Listing|Partner|null $record): bool => $record !== null && SiteResolver::for($record) !== null);
    }

    public static function header(string $name = 'manage_products'): PageAction
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
            ->label('Products')
            ->icon('heroicon-o-shopping-bag')
            ->color('gray')
            ->modalHeading('Shop products')
            ->modalDescription(
                'Products shown in the shop section of this website. '
                .'Each gets its own page. Import runs in the background — '
                .'close and reopen this window to see newly imported products.'
            )
            ->modalSubmitActionLabel('Save')
            ->modalWidth('5xl')
            ->fillForm(fn (Listing|Partner|null $record): array => self::fill($record))
            ->form(fn (Listing|Partner|null $record): array => [
                // What the form was showing when it opened. Saving may only
                // delete products in this set — see write().
                Forms\Components\Hidden::make('known_product_ids'),

                Forms\Components\Actions::make([
                    FormAction::make('import_instagram')
                        ->label('Import from Instagram')
                        ->icon('heroicon-o-camera')
                        ->color('gray')
                        ->form([
                            Forms\Components\TextInput::make('instagram_url')
                                ->label('Profile URL or @username')
                                ->placeholder('@myshop  or  https://instagram.com/myshop')
                                ->helperText('Must be a public profile. Each post becomes one product.')
                                ->required(),
                            Forms\Components\TextInput::make('limit')
                                ->label('Maximum posts to import')
                                ->numeric()
                                ->default(12)
                                ->minValue(1)
                                ->maxValue(30),
                        ])
                        ->modalHeading('Import from Instagram')
                        ->modalDescription(
                            'Downloads the most recent posts from the public Instagram profile. '
                            .'Each post image becomes the product photo; the caption becomes the description. '
                            .'Existing products are not duplicated.'
                        )
                        ->modalSubmitActionLabel('Import')
                        ->action(function (Listing|Partner|null $record, array $data): void {
                            $site = $record === null ? null : SiteResolver::for($record);

                            if ($site === null) {
                                return;
                            }

                            $userId = auth()->id();

                            ImportInstagramProductsJob::dispatch(
                                $site,
                                trim((string) $data['instagram_url']),
                                (int) ($data['limit'] ?? 12),
                                is_numeric($userId) ? (int) $userId : null,
                            );

                            Notification::make()
                                ->title('Importing from Instagram')
                                ->body('Running in the background — the bell will say how it went. '
                                    .'Reopen this window when it does to see the new products.')
                                ->success()
                                ->send();
                        }),

                    FormAction::make('import_photos')
                        ->label('Upload Photos')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->color('gray')
                        ->modalHeading('Upload product photos')
                        ->modalDescription(
                            'Select one or more photos — each becomes a draft product. '.
                            'Use the AI buttons to generate titles and descriptions from the images.'
                        )
                        ->modalSubmitActionLabel('Create products')
                        ->form(fn (Listing|Partner|null $record): array => [
                            Forms\Components\FileUpload::make('photos')
                                ->label('Photos')
                                ->multiple()
                                ->image()
                                ->disk('r2')
                                ->directory(function () use ($record): string {
                                    return SiteResolver::for($record)?->mediaPrefix() ?? 'sites';
                                })
                                ->fetchFileInformation(false)
                                ->dehydrated(false)
                                ->storeFiles(false)
                                ->live()
                                ->afterStateUpdated(function (?array $state, Get $get, Set $set) use ($record): void {
                                    $site = SiteResolver::for($record);
                                    $current = $get('upload_items') ?? [];
                                    $existingTempKeys = array_column($current, 'temp_key');

                                    $items = $current;

                                    // Filament keys FileUpload state by a UUID it generates per
                                    // file, and the value is a live TemporaryUploadedFile — not
                                    // a string. The UUID is the only stable identity here, so it
                                    // is what we dedupe on across re-renders.
                                    foreach ($state ?? [] as $uuid => $file) {
                                        if (! $file instanceof TemporaryUploadedFile) {
                                            continue;
                                        }

                                        if (in_array($uuid, $existingTempKeys, true)) {
                                            continue;
                                        }

                                        // Move to R2 immediately so the preview URL is a real
                                        // public R2 URL, not a temp path we cannot serve.
                                        [$r2Key, $previewUrl] = $site !== null
                                            ? self::moveTempToR2($file, $site)
                                            : [null, null];

                                        $items[] = [
                                            'temp_key' => $uuid,
                                            'image_key' => $r2Key,
                                            'preview_url' => $previewUrl ?? '',
                                            'title' => '',
                                            'description' => '',
                                            'price' => '',
                                        ];
                                    }

                                    $set('upload_items', $items);
                                }),

                            Forms\Components\Actions::make([
                                FormAction::make('ai_all')
                                    ->label('AI: Fill all titles and descriptions')
                                    ->icon('heroicon-o-sparkles')
                                    ->color('primary')
                                    ->hidden(fn (Get $get): bool => empty($get('upload_items')))
                                    ->action(function (Get $get, Set $set): void {
                                        $items = $get('upload_items') ?? [];

                                        if ($items === []) {
                                            return;
                                        }

                                        $urls = array_map(
                                            fn (array $item): string => Storage::disk('r2')->url($item['image_key'] ?? ''),
                                            $items
                                        );

                                        $results = app(ShopProductDescriber::class)->describeMany(array_values($urls));

                                        $newItems = [];

                                        foreach (array_values($items) as $i => $item) {
                                            $newItems[] = array_merge($item, [
                                                'title' => $results[$i]['title'] ?? ($item['title'] ?: ''),
                                                'description' => $results[$i]['description'] ?? ($item['description'] ?: ''),
                                            ]);
                                        }

                                        $set('upload_items', $newItems);
                                    }),
                            ]),

                            Forms\Components\Repeater::make('upload_items')
                                ->label('')
                                ->default([])
                                ->addable(false)
                                ->reorderableWithDragAndDrop(false)
                                ->collapsible(false)
                                ->deletable(true)
                                ->columns(4)
                                ->hidden(fn (Get $get): bool => empty($get('upload_items')))
                                ->schema([
                                    Forms\Components\Hidden::make('temp_key'),
                                    Forms\Components\Hidden::make('image_key'),
                                    Forms\Components\Hidden::make('preview_url'),

                                    Forms\Components\Placeholder::make('_preview')
                                        ->label('')
                                        ->content(fn (Get $get): HtmlString => new HtmlString(
                                            '<div class="relative w-full overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-800" style="aspect-ratio:1/1">'
                                            .($get('preview_url') !== ''
                                                ? '<img src="'.e($get('preview_url')).'" class="absolute inset-0 w-full h-full object-cover" loading="lazy" />'
                                                : '')
                                            .'</div>'
                                        ))
                                        ->columnSpan(1),

                                    Forms\Components\Group::make([
                                        Forms\Components\TextInput::make('title')
                                            ->label('Title')
                                            ->required()
                                            ->placeholder('Product name'),

                                        Forms\Components\Textarea::make('description')
                                            ->label('Description')
                                            ->rows(2)
                                            ->placeholder('Short product description'),

                                        Forms\Components\Group::make([
                                            Forms\Components\TextInput::make('price')
                                                ->label('Price')
                                                ->placeholder('N$ 350'),

                                            Forms\Components\Actions::make([
                                                FormAction::make('ai_item')
                                                    ->label('AI')
                                                    ->icon('heroicon-o-sparkles')
                                                    ->tooltip('Generate title and description from this image')
                                                    ->action(function (Get $get, Set $set): void {
                                                        $url = Storage::disk('r2')->url($get('image_key') ?? '');
                                                        $result = app(ShopProductDescriber::class)->describe($url);
                                                        $set('title', $result['title']);
                                                        $set('description', $result['description']);
                                                    }),
                                            ])->verticallyAlignEnd(),
                                        ])->columns(2),
                                    ])->columnSpan(3),
                                ]),

                            Forms\Components\Toggle::make('publish')
                                ->label('Publish straight away')
                                ->helperText('You have just reviewed every title and description above, '
                                    .'so there is nothing left to check. Turn this off to create them as drafts instead.')
                                ->default(true)
                                ->hidden(fn (Get $get): bool => empty($get('upload_items'))),
                        ])
                        ->action(function (Listing|Partner|null $record, array $data, Set $set): void {
                            $site = $record === null ? null : SiteResolver::for($record);

                            if ($site === null) {
                                return;
                            }

                            $status = ($data['publish'] ?? true)
                                ? ShopProductStatus::Published
                                : ShopProductStatus::Draft;

                            $maxSort = $site->shopProducts()->max('sort') ?? -1;
                            $created = 0;

                            foreach ($data['upload_items'] ?? [] as $item) {
                                $imageKey = is_string($item['image_key'] ?? null) ? $item['image_key'] : null;
                                $title = trim((string) ($item['title'] ?? ''));

                                if ($imageKey === null || $title === '') {
                                    continue;
                                }

                                $imageId = SiteImage::create([
                                    'site_id' => $site->id,
                                    'key' => $imageKey,
                                    'content_source' => ContentSource::Partner,
                                    'prospect_only' => false,
                                    'sort' => 0,
                                ])->id;

                                ShopProduct::create([
                                    'site_id' => $site->id,
                                    'title' => mb_substr($title, 0, 255),
                                    'description' => filled($item['description'] ?? null) ? trim((string) $item['description']) : null,
                                    ...self::parsePrice((string) ($item['price'] ?? '')),
                                    'status' => $status,
                                    'image_ids' => [$imageId],
                                    'sort' => ++$maxSort,
                                ]);

                                $created++;
                            }

                            // Pull the new products into the parent form. Without this
                            // the list behind this modal still shows what was there when
                            // it opened, and saving it would delete everything just made.
                            foreach (self::fill($record) as $key => $value) {
                                $set($key, $value);
                            }

                            Notification::make()
                                ->title($created.' '.($created === 1 ? 'product' : 'products').' created')
                                ->body($status === ShopProductStatus::Published
                                    ? 'Live on the website. They are in the list behind this window.'
                                    : 'Saved as drafts — publish them in the list behind this window.')
                                ->success()
                                ->send();
                        }),

                    FormAction::make('import_file')
                        ->label('Import from Excel / CSV')
                        ->icon('heroicon-o-table-cells')
                        ->color('gray')
                        ->form([
                            Forms\Components\FileUpload::make('file')
                                ->label('Spreadsheet file (.xlsx or .csv)')
                                ->acceptedFileTypes([
                                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                    'text/csv',
                                    'text/plain',
                                    'application/csv',
                                ])
                                ->disk('local')
                                ->directory('tmp/product-imports')
                                ->required(),

                            Forms\Components\Placeholder::make('column_help')
                                ->label('Columns')
                                ->content(
                                    'First row must be the header. Recognised columns: '
                                    .'title (required), price, category, description, image_url. '
                                    .'Images are downloaded from the URLs you supply — '
                                    .'Google Drive, Dropbox and direct image links all work. '
                                    .'Import runs in the background.'
                                ),
                        ])
                        ->modalHeading('Import from a spreadsheet')
                        ->modalSubmitActionLabel('Import')
                        ->action(function (Listing|Partner|null $record, array $data): void {
                            $site = $record === null ? null : SiteResolver::for($record);

                            if ($site === null) {
                                return;
                            }

                            $fileKey = is_array($data['file'] ?? null)
                                ? reset($data['file'])
                                : ($data['file'] ?? null);

                            if (! is_string($fileKey) || $fileKey === '') {
                                return;
                            }

                            $userId = auth()->id();

                            ImportProductsFromFileJob::dispatch(
                                $site,
                                $fileKey,
                                is_numeric($userId) ? (int) $userId : null,
                            );

                            Notification::make()
                                ->title('Importing from spreadsheet')
                                ->body('Running in the background — the bell will say how it went.')
                                ->success()
                                ->send();
                        }),
                ]),

                Forms\Components\Repeater::make('products')
                    ->hiddenLabel()
                    ->addActionLabel('Add a product')
                    ->reorderableWithButtons()
                    ->collapsible()
                    ->itemLabel(fn (array $state): string => filled($state['title'] ?? '') ? (string) $state['title'] : 'New product')
                    ->schema([
                        Forms\Components\Hidden::make('product_id'),
                        Forms\Components\Hidden::make('image_id'),

                        Forms\Components\TextInput::make('title')
                            ->label('Name')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        Forms\Components\Grid::make(2)->schema([
                            // A number, because an order is added up from it.
                            // Leave it empty and the product still shows in the
                            // shop — it just cannot be ordered with a quantity,
                            // which is what `price_text` is for.
                            Forms\Components\TextInput::make('price')
                                ->label('Price')
                                ->helperText('A number. Orders are totalled from this.')
                                ->numeric()
                                ->minValue(0)
                                ->placeholder('350'),

                            Forms\Components\TextInput::make('price_text')
                                ->label('Price as text')
                                ->helperText('Used only when there is no number — "from N$ 850", "Call for price". Not orderable.')
                                ->maxLength(100)
                                ->placeholder('Call for price'),

                            Forms\Components\TextInput::make('category')
                                ->label('Category')
                                ->maxLength(100)
                                ->placeholder('Jewellery, Textiles, …'),

                            Forms\Components\Select::make('status')
                                ->label('Visibility')
                                ->options(collect(ShopProductStatus::cases())->mapWithKeys(
                                    fn (ShopProductStatus $s) => [$s->value => $s->label()]
                                )->all())
                                ->default(ShopProductStatus::Published->value)
                                ->native(false),

                            Forms\Components\FileUpload::make('image_key')
                                ->label('Photo')
                                ->image()
                                ->disk('r2')
                                ->directory(fn (Listing|Partner|null $record): string => self::prefixFor($record))
                                ->imageEditor()
                                ->fetchFileInformation(false),
                        ]),

                        Forms\Components\Textarea::make('description')
                            ->label('Description')
                            ->rows(3)
                            ->maxLength(4000)
                            ->columnSpanFull(),
                    ]),
            ])
            ->action(function (Listing|Partner|null $record, array $data): void {
                $site = $record === null ? null : SiteResolver::for($record);

                if ($site === null) {
                    return;
                }

                self::write(
                    $site,
                    is_array($data['products'] ?? null) ? $data['products'] : [],
                    self::ids($data['known_product_ids'] ?? null),
                );
            });
    }

    /**
     * The form's initial state, including the record of which products it is
     * showing. Also used to refresh the form after a nested import creates
     * products behind it.
     *
     * @return array{products: array<int, array<string, mixed>>, known_product_ids: string}
     */
    private static function fill(Listing|Partner|null $record): array
    {
        $products = self::state($record);

        return [
            'products' => $products,
            'known_product_ids' => implode(',', array_column($products, 'product_id')),
        ];
    }

    /**
     * Parse the hidden id list back into integers.
     *
     * @return array<int, int>
     */
    private static function ids(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        return array_values(array_map(
            intval(...),
            array_filter(explode(',', $value), is_numeric(...))
        ));
    }

    /**
     * The product rows, in the shape the form's own components expect.
     *
     * `image_key` is emitted as `[uuid => key]` rather than as the bare key,
     * because that is what a FileUpload's state is once it is loaded: its
     * `afterStateHydrated` wraps the value, and everything downstream —
     * `getUploadedFiles()` among them — then iterates it.
     *
     * Handing it the bare key works only as long as hydration is the sole way
     * the state is filled. It is not: `Set` writes state directly and runs no
     * hydration callbacks, so refreshing the list after an import left a string
     * where a FileUpload expected an array, and the next Livewire request died
     * in `foreach`. Producing the loaded shape here means both paths agree.
     * `key()` unwraps it again on the way back out.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function state(Listing|Partner|null $record): array
    {
        $site = $record === null ? null : SiteResolver::for($record);

        if ($site === null) {
            return [];
        }

        return $site->shopProducts()->orderBy('sort')->get()
            ->map(function (ShopProduct $product): array {
                $imageId = $product->image_ids[0] ?? null;
                $imageKey = null;

                if (is_int($imageId)) {
                    $imageKey = SiteImage::find($imageId)?->key;
                }

                return [
                    'product_id' => $product->id,
                    'image_id' => $imageId,
                    'title' => $product->title,
                    'price' => $product->price,
                    'price_text' => $product->price_text,
                    'category' => $product->category,
                    'status' => $product->status->value,
                    'image_key' => $imageKey === null ? [] : [(string) Str::uuid() => $imageKey],
                    'description' => $product->description,
                ];
            })
            ->all();
    }

    /**
     * Write the submitted product list back to the site.
     *
     * `$knownIds` is what the form was showing when it opened. A product may
     * only be deleted if it is in that set: removing a row from the repeater is
     * the *only* way this form can say "delete", and it can only say it about a
     * product it was actually displaying.
     *
     * Without that scope, an empty repeater means "delete everything" — and the
     * repeater is legitimately empty in a case that happens constantly: the
     * modal is opened, a nested import (photo upload, Instagram, spreadsheet)
     * creates products behind it, and the stale form is then saved. That wiped
     * 11 freshly uploaded products on 2026-08-16. The form is refreshed after
     * an import now, but this scope is the guarantee — a stale form can no
     * longer delete a product it never knew about.
     *
     * @param  array<int, mixed>  $state
     * @param  array<int, int>  $knownIds
     */
    private static function write(Site $site, array $state, array $knownIds): void
    {
        $keptIds = [];
        $sort = 0;

        foreach ($state as $entry) {
            if (! is_array($entry) || blank($entry['title'] ?? null)) {
                continue;
            }

            $imageKey = self::key($entry['image_key'] ?? null);
            $imageId = is_numeric($entry['image_id'] ?? null) ? (int) $entry['image_id'] : null;
            $productId = is_numeric($entry['product_id'] ?? null) ? (int) $entry['product_id'] : null;
            $status = ShopProductStatus::tryFrom((string) ($entry['status'] ?? '')) ?? ShopProductStatus::Published;

            // Resolve the image: create or update a SiteImage for the upload.
            $resolvedImageIds = self::resolveImage($site, $imageKey, $imageId, $productId);

            $attrs = [
                'title' => trim((string) $entry['title']),
                'price' => filled($entry['price'] ?? null) ? (float) $entry['price'] : null,
                'price_text' => filled($entry['price_text'] ?? null) ? trim((string) $entry['price_text']) : null,
                'category' => filled($entry['category'] ?? null) ? trim((string) $entry['category']) : null,
                'status' => $status,
                'image_ids' => $resolvedImageIds,
                'description' => filled($entry['description'] ?? null) ? trim((string) $entry['description']) : null,
                'sort' => $sort,
            ];

            if ($productId !== null) {
                $existing = $site->shopProducts()->whereKey($productId)->first();

                if ($existing instanceof ShopProduct) {
                    $existing->update($attrs);
                    $keptIds[] = $existing->id;
                    $sort++;

                    continue;
                }
            }

            $product = ShopProduct::create(['site_id' => $site->id] + $attrs);
            $keptIds[] = $product->id;
            $sort++;
        }

        $removedIds = array_values(array_diff($knownIds, $keptIds));

        if ($removedIds !== []) {
            $site->shopProducts()->whereIn('id', $removedIds)->delete();
        }

        $count = $site->shopProducts()->count();

        Notification::make()
            ->title('Saved')
            ->body($count.' '.($count === 1 ? 'product' : 'products').' on this site.')
            ->success()
            ->send();
    }

    /**
     * Create or update the SiteImage for a product's primary photo.
     *
     * Returns the resolved image_ids array (a single-element array or empty).
     * Deliberately never deletes a SiteImage — orphan cleanup is `photos:audit-r2`.
     *
     * @return array<int, int>
     */
    private static function resolveImage(Site $site, ?string $imageKey, ?int $imageId, ?int $productId): array
    {
        if ($imageKey === null) {
            // No image: clear the first slot only. If there were additional
            // images (added via the admin resource), keep them.
            if ($productId !== null && $imageId !== null) {
                $product = $site->shopProducts()->whereKey($productId)->first();

                if ($product instanceof ShopProduct) {
                    $remaining = array_values(array_filter(
                        $product->image_ids,
                        fn (int $id): bool => $id !== $imageId
                    ));

                    return $remaining;
                }
            }

            return [];
        }

        // An image key is present. Find an existing SiteImage to update, or create one.
        $existing = $imageId !== null
            ? $site->images()->whereKey($imageId)->first()
            : null;

        if ($existing instanceof SiteImage) {
            if ($existing->key !== $imageKey) {
                $existing->update(['key' => $imageKey]);
            }

            return [$existing->id];
        }

        $newImage = SiteImage::create([
            'site_id' => $site->id,
            'key' => $imageKey,
            'content_source' => ContentSource::Partner,
            'prospect_only' => false,
            'sort' => 0,
        ]);

        return [$newImage->id];
    }

    /**
     * Extract a plain storage key from what Filament's FileUpload hands back.
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

    private static function prefixFor(Listing|Partner|null $record): string
    {
        $site = $record === null ? null : SiteResolver::for($record);

        return $site === null ? 'sites' : $site->mediaPrefix();
    }

    /**
     * Move a Livewire temporary upload to R2 immediately, so the preview URL
     * is a real public R2 URL rather than a temp-storage path we cannot serve.
     *
     * Read the bytes through the file object's own `get()`. It knows which disk
     * and directory Livewire is configured with; reconstructing that path here
     * gets it wrong, because Livewire writes those config keys as null to mean
     * "use the default" and `config(..., 'local')` then returns null, not the
     * fallback.
     *
     * Leaves the temp file in place — Livewire's own cleanup removes it later.
     * A failure is logged and returns [null, null]: the row renders without a
     * preview instead of taking the whole upload down with it.
     *
     * @return array{0: string|null, 1: string|null}  [r2Key, publicUrl]
     */
    private static function moveTempToR2(TemporaryUploadedFile $file, Site $site): array
    {
        try {
            $content = $file->get();

            if (! is_string($content) || $content === '') {
                return [null, null];
            }

            $ext = strtolower($file->getClientOriginalExtension() ?: '') ?: 'jpg';
            $r2Key = $site->mediaPrefix().'/'.Str::random(20).'.'.$ext;
            Storage::disk('r2')->put($r2Key, $content, 'public');

            return [$r2Key, Storage::disk('r2')->url($r2Key)];
        } catch (Throwable $e) {
            Log::warning('Product photo upload: could not move temp file to R2', [
                'site_id' => $site->id,
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return [null, null];
        }
    }

    /**
     * Parse a freeform price string into the split price/price_text columns.
     *
     * A plain number, optionally prefixed with a currency mark, becomes a
     * decimal `price`; anything with a qualifier stays as `price_text` only.
     *
     * @return array{price: float|null, price_text: string|null}
     */
    private static function parsePrice(string $raw): array
    {
        $text = trim($raw);

        if ($text === '') {
            return ['price' => null, 'price_text' => null];
        }

        $bare = preg_replace('/^(?:N\$|R|\$|€|£|ZAR|NAD|EUR|USD|GBP)\s*/iu', '', $text) ?? $text;
        $bare = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($bare));

        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $bare) === 1) {
            return ['price' => (float) $bare, 'price_text' => null];
        }

        return ['price' => null, 'price_text' => mb_substr($text, 0, 100)];
    }
}
