<?php

namespace App\Filament\Resources;

use App\Enums\AttractionType;
use App\Enums\ContentSource;
use App\Filament\Resources\AttractionResource\Pages;
use App\Filament\Support\PipelineImageResolver;
use App\Http\Controllers\Controller;
use App\Models\Attraction;
use App\Models\Place;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Concerns\Translatable;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * A thing a traveller goes and looks at, where nothing is sold — Deadvlei, the
 * Hoba Meteorite, the dinosaur footprints, a rock-art site with no lodge on it.
 *
 * Not a Listing (those have a price and a partner) and not a Place (nothing is
 * filed under an attraction). What makes this table worth filling is the
 * *Visiting* section: how long you need, what it costs, whether the track needs
 * 4x4, whether a permit is required. Encyclopedic facts are free everywhere;
 * that is not, and it is what turns an answer into a day in a plan.
 */
class AttractionResource extends Resource
{
    use Translatable;

    protected static ?string $model = Attraction::class;

    protected static ?string $navigationIcon = 'heroicon-o-camera';

    protected static ?string $navigationGroup = 'Content';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('What it is')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('slug')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Forms\Components\Select::make('type')
                            ->options(AttractionType::class)
                            ->required(),
                        Forms\Components\Textarea::make('summary')
                            ->label('One line')
                            ->helperText('The single sentence Kaia may be handed beside a name and a coordinate. Keep it short — the long text never reaches the trip plan.')
                            ->rows(2)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('description')
                            ->helperText('The full text, for the detail page. Deliberately not sent to the trip plan.')
                            ->rows(6)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Where it is')
                    ->description('Coordinates are what make it findable — "what is near here", "what is on this road". Without them it is a page, not an answer.')
                    ->schema([
                        Forms\Components\Select::make('place_id')
                            ->label('Place')
                            ->relationship('place', 'slug')
                            ->getOptionLabelFromRecordUsing(fn (Place $record): string => (string) $record->name)
                            ->searchable(['slug'])
                            ->preload()
                            ->optionsLimit(300)
                            ->helperText('The tourism location it sits in — Deadvlei is in Sossusvlei. Empty for something standing on its own beside a road.'),
                        Forms\Components\Select::make('city_id')
                            ->label('Nearest town')
                            ->relationship('city', 'name')
                            ->searchable()
                            ->preload()
                            ->optionsLimit(300)
                            ->helperText('What "I am in Tsumeb for two days" resolves against.'),
                        Forms\Components\TextInput::make('lat')
                            ->label('Latitude')
                            ->numeric()
                            ->helperText('Decimal degrees, e.g. -19.5925'),
                        Forms\Components\TextInput::make('lng')
                            ->label('Longitude')
                            ->numeric()
                            ->helperText('Decimal degrees, e.g. 17.9333'),
                    ])
                    ->columns(2),

                Forms\Components\Section::make('Visiting')
                    ->description('The part nobody can copy from a search engine. Leave a toggle unset where it has not been checked — unset means "not established", not "no".')
                    ->schema([
                        Forms\Components\TextInput::make('visit_minutes')
                            ->label('How long to allow (minutes)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(1440),
                        Forms\Components\TextInput::make('entry_fee')
                            ->label('Entry fee')
                            ->numeric()
                            ->helperText('0 is free, and worth stating. Empty means nobody has checked.'),
                        Forms\Components\TextInput::make('entry_fee_currency')
                            ->label('Currency')
                            ->default('NAD')
                            ->maxLength(3),
                        Forms\Components\Select::make('requires_4x4')
                            ->label('Needs 4x4')
                            ->boolean()
                            ->placeholder('Not established'),
                        Forms\Components\Select::make('requires_permit')
                            ->label('Needs a permit')
                            ->boolean()
                            ->placeholder('Not established'),
                        Forms\Components\Textarea::make('access_note')
                            ->label('Getting there')
                            ->helperText('Which track, what the road does after rain, where to leave the car.')
                            ->rows(3)
                            ->columnSpanFull(),
                        Forms\Components\Textarea::make('best_time_note')
                            ->label('When to go')
                            ->helperText('Season, time of day, what light it wants.')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Forms\Components\Section::make('Photos and rights')
                    ->description('A third-party directory\'s prose and photography stay internal no matter how they got here — editing them does not make them ours.')
                    ->schema([
                        Forms\Components\FileUpload::make('image')
                            ->image()
                            ->disk('r2')
                            ->directory('attractions')
                            ->imageEditor()
                            ->fetchFileInformation(false)
                            ->getUploadedFileUsing(PipelineImageResolver::resolve(...))
                            ->columnSpanFull(),
                        Forms\Components\Select::make('description_source')
                            ->label('Text from')
                            ->options(ContentSource::class),
                        Forms\Components\Select::make('photos_source')
                            ->label('Photos from')
                            ->options(ContentSource::class),
                        Forms\Components\TextInput::make('photos_attribution')
                            ->label('Photo credit')
                            ->columnSpanFull(),
                        Forms\Components\Toggle::make('is_published')
                            ->helperText('Nothing here auto-publishes.'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('slug')
            ->columns([
                Tables\Columns\ImageColumn::make('image')
                    ->getStateUsing(fn (Attraction $record): ?string => $record->image ? Controller::resolveMediaUrl($record->image) : null)
                    ->square(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->badge(),
                Tables\Columns\TextColumn::make('place.name')
                    ->label('Place')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('city.name')
                    ->label('Nearest town')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('visit_minutes')
                    ->label('Minutes')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('lat')
                    ->label('Findable')
                    ->boolean()
                    ->getStateUsing(fn (Attraction $record): bool => $record->isRoutable())
                    ->tooltip('Without coordinates it cannot answer "what is near here".'),
                Tables\Columns\IconColumn::make('is_published')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(AttractionType::class),
                Tables\Filters\TernaryFilter::make('is_published'),
                Tables\Filters\TernaryFilter::make('lat')
                    ->label('Findable')
                    ->placeholder('All')
                    ->trueLabel('Has coordinates')
                    ->falseLabel('Missing coordinates')
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('lat')->whereNotNull('lng'),
                        false: fn ($query) => $query->whereNull('lat')->orWhereNull('lng'),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
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
            'index' => Pages\ListAttractions::route('/'),
            'create' => Pages\CreateAttraction::route('/create'),
            'edit' => Pages\EditAttraction::route('/{record}/edit'),
        ];
    }
}
