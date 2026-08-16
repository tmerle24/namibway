<?php

namespace App\Filament\Resources;

use App\Enums\ShopProductStatus;
use App\Filament\Resources\ShopProductResource\Pages;
use App\Models\ShopProduct;
use App\Models\Site;
use App\Models\SiteImage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Products on customer websites.
 *
 * Products are scoped to a site. The table shows all products across all sites,
 * with a site filter for working on one at a time. Selecting a site in the form
 * limits the image picker to that site's gallery.
 */
class ShopProductResource extends Resource
{
    protected static ?string $model = ShopProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationLabel = 'Shop Products';

    protected static ?string $navigationGroup = 'Content';

    protected static ?string $modelLabel = 'product';

    protected static ?string $pluralModelLabel = 'shop products';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\Select::make('site_id')
                    ->label('Site')
                    ->options(fn (): array => Site::orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->native(false)
                    ->live()
                    ->searchable(),

                Forms\Components\TextInput::make('title')
                    ->label('Title')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (Forms\Set $set, ?string $state, string $operation) {
                        if ($operation === 'create' && filled($state)) {
                            $set('slug', Str::slug($state));
                        }
                    }),

                Forms\Components\TextInput::make('slug')
                    ->label('URL slug')
                    ->required()
                    ->maxLength(255)
                    ->helperText('Used in the product URL: /shop/this-slug'),

                Forms\Components\Grid::make(2)->schema([
                    Forms\Components\TextInput::make('price')
                        ->label('Price')
                        ->maxLength(100)
                        ->placeholder('N$ 350'),

                    Forms\Components\TextInput::make('category')
                        ->label('Category')
                        ->maxLength(100)
                        ->placeholder('Jewellery, Textiles, …'),
                ]),

                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(collect(ShopProductStatus::cases())->mapWithKeys(
                        fn (ShopProductStatus $s) => [$s->value => $s->label()]
                    )->all())
                    ->required()
                    ->native(false)
                    ->default(ShopProductStatus::Draft->value),

                Forms\Components\TextInput::make('sort')
                    ->label('Sort order')
                    ->integer()
                    ->default(0)
                    ->helperText('Lower numbers appear first.'),
            ])->columns(1),

            Forms\Components\Section::make('Description')->schema([
                Forms\Components\Textarea::make('description')
                    ->label('Description')
                    ->rows(5)
                    ->maxLength(4000)
                    ->columnSpanFull(),
            ]),

            Forms\Components\Section::make('Images')->schema([
                Forms\Components\CheckboxList::make('image_ids')
                    ->label('Pictures from the site gallery')
                    ->options(fn (Get $get): array => self::imageOptions((int) $get('site_id')))
                    ->columns(2)
                    ->bulkToggleable()
                    ->helperText('Upload images in the site\'s media gallery first, then select them here.'),
            ]),

            Forms\Components\Section::make('Source')->schema([
                Forms\Components\TextInput::make('instagram_post_url')
                    ->label('Instagram post URL')
                    ->url()
                    ->maxLength(500)
                    ->helperText('Filled automatically when imported from Instagram.'),
            ])->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('site.name')
                    ->label('Site')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('title')
                    ->label('Title')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('category')
                    ->label('Category')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('price')
                    ->label('Price')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ShopProductStatus $state): string => $state->label())
                    ->color(fn (ShopProductStatus $state): string => match ($state) {
                        ShopProductStatus::Draft => 'gray',
                        ShopProductStatus::Published => 'success',
                    }),

                Tables\Columns\TextColumn::make('sort')
                    ->label('Sort')
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime('d M Y')
                    ->sortable(),
            ])
            ->defaultSort('site_id')
            ->filters([
                Tables\Filters\SelectFilter::make('site_id')
                    ->label('Site')
                    ->options(fn (): array => Site::orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->native(false),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(ShopProductStatus::cases())->mapWithKeys(
                        fn (ShopProductStatus $s) => [$s->value => $s->label()]
                    )->all()),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShopProducts::route('/'),
            'create' => Pages\CreateShopProduct::route('/create'),
            'edit' => Pages\EditShopProduct::route('/{record}/edit'),
        ];
    }

    /** @return array<int, string> */
    private static function imageOptions(int $siteId): array
    {
        if ($siteId === 0) {
            return [];
        }

        return SiteImage::where('site_id', $siteId)
            ->orderBy('sort')
            ->get()
            ->mapWithKeys(fn (SiteImage $img): array => [
                $img->id => $img->alt ?: basename((string) $img->key),
            ])
            ->all();
    }
}
