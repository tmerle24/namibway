<?php

namespace App\Filament\Resources;

use App\Connectors\ConnectorFactory;
use App\Enums\ConnectorType;
use App\Enums\ListingType;
use App\Filament\Resources\ListingResource\Pages;
use App\Models\Listing;
use App\Models\Partner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Concerns\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Enums\ActionsPosition;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListingResource extends Resource
{
    use Translatable;

    protected static ?string $model = Listing::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // Must be the r2 disk, not public — AI/website/Google-Places-scraped images
                // are stored on R2 (see GooglePlacesPhotoFinder, WebsiteContentExtractor::
                // downloadPhoto), so a FileUpload on the 'public' disk can't resolve their
                // preview URLs at all: it silently shows no thumbnail for anything scraped.
                Forms\Components\FileUpload::make('image')
                    ->label('Hero image')
                    ->image()
                    ->disk('r2')
                    ->directory('listings')
                    ->imageEditor()
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('gallery')
                    ->image()
                    ->multiple()
                    ->reorderable()
                    ->disk('r2')
                    ->directory('listings/gallery')
                    ->columnSpanFull(),
                Forms\Components\Select::make('type')
                    ->options(ListingType::class)
                    ->required(),
                Forms\Components\Select::make('partner_id')
                    ->label('Partner')
                    ->relationship('partner', 'name')
                    ->searchable()
                    ->preload()
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')->required(),
                        Forms\Components\TextInput::make('email')->email(),
                    ]),
                Forms\Components\TextInput::make('wetu_id')
                    ->label('Wetu property ID')
                    ->placeholder('e.g. WETU-001')
                    ->helperText('Set this to enable automatic content sync from Wetu'),
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('slug')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\TagsInput::make('highlights')
                    ->placeholder('Add a highlight and press enter')
                    ->helperText('Short USPs shown on the detail page, e.g. "Free WiFi", "Waterhole views"')
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('region')
                    ->maxLength(255),
                Forms\Components\TextInput::make('latitude')
                    ->numeric(),
                Forms\Components\TextInput::make('longitude')
                    ->numeric(),
                Forms\Components\TextInput::make('price_from')
                    ->numeric(),
                Forms\Components\TextInput::make('price_currency')
                    ->required()
                    ->maxLength(3)
                    ->default('NAD'),
                Forms\Components\TextInput::make('rating')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(5)
                    ->step(0.1)
                    ->helperText('Average rating out of 5, e.g. from Google/TripAdvisor'),
                Forms\Components\TextInput::make('rating_count')
                    ->label('Number of ratings')
                    ->numeric()
                    ->minValue(0),
                Forms\Components\Toggle::make('is_featured')
                    ->required(),
                Forms\Components\Toggle::make('is_published')
                    ->required(),
                Forms\Components\Toggle::make('accepts_inquiries')
                    ->label('Accepts inquiries')
                    ->helperText('Shows the contact/inquiry form on the detail page')
                    ->default(true)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->disk('public')
                    ->square()
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $direction === 'desc'
                        ? $query->orderByRaw("(image IS NOT NULL AND image != '') desc")
                        : $query->orderByRaw("(image IS NOT NULL AND image != '') asc")),
                Tables\Columns\TextColumn::make('type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->wrap(),
                Tables\Columns\TextColumn::make('partner.name')
                    ->label('Partner')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('region')
                    ->searchable(),
                Tables\Columns\TextColumn::make('latitude')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('longitude')
                    ->numeric()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('price_from')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_currency')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('rating')
                    ->numeric(1)
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_featured')
                    ->boolean(),
                Tables\Columns\IconColumn::make('is_published')
                    ->boolean(),
                Tables\Columns\TextColumn::make('claim_status')
                    ->label('Claim')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'claimed' => 'success',
                        'rejected' => 'danger',
                        'unclaimed' => 'warning',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('scrape_source')
                    ->label('Source')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(ListingType::class),
                Tables\Filters\SelectFilter::make('claim_status')
                    ->options([
                        'unclaimed' => 'Unclaimed',
                        'claimed' => 'Claimed',
                        'rejected' => 'Rejected',
                    ]),
                Tables\Filters\TernaryFilter::make('is_published'),
                Tables\Filters\TernaryFilter::make('has_image')
                    ->label('Has image')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('image')->where('image', '!=', ''),
                        false: fn (Builder $query) => $query->where(fn ($q) => $q->whereNull('image')->orWhere('image', '')),
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('view_frontend')
                    ->label('')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->tooltip(fn (Listing $record): string => $record->is_published
                        ? 'View on namibway.com'
                        : 'Preview draft on namibway.com (not published yet — visible to admins only)')
                    ->url(fn (Listing $record): string => route('listings.show', $record->slug))
                    ->openUrlInNewTab(),
                Tables\Actions\Action::make('import_wetu')
                    ->label('Import from Wetu')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    ->visible(fn (Listing $record): bool => $record->partner?->connector_type === ConnectorType::Wetu)
                    ->requiresConfirmation()
                    ->modalHeading('Import content from Wetu')
                    ->modalDescription('This will overwrite the name, description, highlights, region, and coordinates with data from Wetu. Existing images are not replaced.')
                    ->action(function (Listing $record): void {
                        $partner = $record->partner;

                        if (! $partner instanceof Partner) {
                            Notification::make()->title('No partner linked to this listing.')->danger()->send();

                            return;
                        }

                        try {
                            $wetuId = $partner->connector_property_code;

                            if (blank($wetuId)) {
                                Notification::make()->title('Partner has no Wetu property code configured.')->danger()->send();

                                return;
                            }

                            $content = ConnectorFactory::makeContent($partner)->fetchPropertyContent($wetuId);

                            $record->update(array_filter([
                                'name' => $content->name ?: null,
                                'description' => $content->description,
                                'highlights' => $content->highlights ?: null,
                                'region' => $content->region,
                                'latitude' => $content->latitude,
                                'longitude' => $content->longitude,
                            ], fn ($v) => $v !== null));

                            Notification::make()
                                ->title('Content imported from Wetu')
                                ->body("Imported: {$content->name}")
                                ->success()
                                ->send();

                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Wetu import failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ], position: ActionsPosition::BeforeColumns)
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('publish')
                        ->label('Publish')
                        ->icon('heroicon-o-eye')
                        ->color('success')
                        ->action(fn ($records) => $records->each->update(['is_published' => true]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\BulkAction::make('unpublish')
                        ->label('Unpublish')
                        ->icon('heroicon-o-eye-slash')
                        ->color('gray')
                        ->action(fn ($records) => $records->each->update(['is_published' => false]))
                        ->deselectRecordsAfterCompletion(),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListListings::route('/'),
            'create' => Pages\CreateListing::route('/create'),
            'edit' => Pages\EditListing::route('/{record}/edit'),
        ];
    }
}
