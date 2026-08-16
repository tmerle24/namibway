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
use Filament\Actions\Action as PageAction;
use Filament\Forms;
use Filament\Forms\Components\Actions\Action as FormAction;
use Filament\Notifications\Notification;

/**
 * Manage shop products for a listing's website.
 *
 * Follows the same two-variant pattern as EditSiteImagesAction — a FormAction
 * for the admin WebsiteTab and a PageAction for the partner listing header.
 * Both resolve the site via source_listing_id.
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

    public static function partnerHeader(string $name = 'manage_products'): PageAction
    {
        return self::configure(PageAction::make($name))
            ->visible(fn (Partner $record): bool => SiteResolver::for($record) !== null);
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
            ->fillForm(fn (Listing|Partner|null $record): array => ['products' => self::state($record)])
            ->form(fn (Listing|Partner|null $record): array => [
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

                self::write($site, is_array($data['products'] ?? null) ? $data['products'] : []);
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
                    'image_key' => $imageKey,
                    'description' => $product->description,
                ];
            })
            ->all();
    }

    /**
     * @param  array<int, mixed>  $state
     */
    private static function write(Site $site, array $state): void
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

        $site->shopProducts()
            ->whereNotIn('id', $keptIds === [] ? [0] : $keptIds)
            ->delete();

        $count = count($keptIds);

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
}
