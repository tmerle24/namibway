<?php

namespace App\Filament\Partner\Resources;

use App\Filament\Partner\Resources\ListingResource\Pages;
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
                        Forms\Components\FileUpload::make('image')
                            ->label('Hero image')
                            ->image()
                            ->disk('public')
                            ->directory('listings')
                            ->imageEditor()
                            ->columnSpanFull(),
                        Forms\Components\FileUpload::make('gallery')
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->disk('public')
                            ->directory('listings/gallery')
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
