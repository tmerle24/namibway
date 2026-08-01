<?php

namespace App\Filament\Partner\Resources;

use App\Filament\Partner\Resources\ListingResource\Pages;
use App\Filament\Support\BookingConnectorSchema;
use App\Filament\Support\PipelineImageResolver;
use App\Models\Listing;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'My Listings';

    protected static ?string $breadcrumb = 'Listings';

    /** @return Builder<Listing> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('partner_id', auth()->user()?->partner_id);
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Content')
                    ->description('This content was auto-imported from Wetu. You can edit any field to override it.')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Textarea::make('description')
                            ->rows(5)
                            ->columnSpanFull(),
                        Forms\Components\TagsInput::make('highlights')
                            ->placeholder('Add a highlight...')
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('region')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('price_from')
                            ->numeric()
                            ->prefix('NAD')
                            ->label('Price from (per night)'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Photos')
                    ->schema([
                        // fetchFileInformation(false) + getUploadedFileUsing() together: see
                        // the comment on the equivalent fields in the admin ListingResource —
                        // partner-owned listings' image/gallery values can just as easily be
                        // full R2 URLs or paths relative to a different disk (set via the
                        // admin panel or the enrichment pipeline), which the default resolver
                        // silently drops before it's even reached.
                        Forms\Components\FileUpload::make('image')
                            ->label('Hero image')
                            ->image()
                            ->disk('public')
                            ->directory('listings')
                            ->imageEditor()
                            ->fetchFileInformation(false)
                            ->getUploadedFileUsing(PipelineImageResolver::resolve(...))
                            ->columnSpanFull(),
                        Forms\Components\FileUpload::make('gallery')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->disk('public')
                            ->directory('listings/gallery')
                            ->fetchFileInformation(false)
                            ->getUploadedFileUsing(PipelineImageResolver::resolve(...))
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Settings')
                    ->schema([
                        Forms\Components\Toggle::make('accepts_inquiries')
                            ->label('Accept booking inquiries'),
                        Forms\Components\Placeholder::make('content_synced_at')
                            ->label('Content last synced from Wetu')
                            ->content(fn (Listing $record): string => $record->content_synced_at
                                ? $record->content_synced_at->diffForHumans()
                                : 'Never'
                            )
                            ->visibleOn('edit'),
                    ])
                    ->columns(2),

                Forms\Components\Tabs::make('booking_connector_tabs')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Booking system')
                            ->icon('heroicon-o-link')
                            ->schema(BookingConnectorSchema::schema()),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->disk('public')
                    ->circular(false)
                    ->width(60)
                    ->height(40),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('region')
                    ->sortable(),
                Tables\Columns\IconColumn::make('accepts_inquiries')
                    ->label('Accepts inquiries')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_published')
                    ->label('Published')
                    ->boolean(),
                Tables\Columns\TextColumn::make('content_synced_at')
                    ->label('Wetu sync')
                    ->since()
                    ->placeholder('Never'),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListListings::route('/'),
            'edit' => Pages\EditListing::route('/{record}/edit'),
        ];
    }
}
