<?php

namespace App\Filament\Resources\ListingResource\RelationManagers;

use App\Http\Controllers\Controller;
use App\Models\Amenity;
use App\Models\RoomType;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/**
 * Room/unit types for a listing — the real bookable inventory behind the trip
 * plan's room picker and the Native connector's availability logic.
 *
 * There was no admin UI for these at all until now: `room_types` could only be
 * populated by seeder or tinker, which is why every listing in production has
 * none, and why the picker was showing invented tiers instead. Availability is
 * derived rather than stored (see App\Services\Booking\RoomAvailability), so
 * what's edited here is capacity and rate — never a calendar.
 */
class RoomTypesRelationManager extends RelationManager
{
    protected static string $relationship = 'roomTypes';

    protected static ?string $title = 'Room types';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->helperText('What the traveler sees, e.g. "Luxury Chalet".')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('code')
                    ->helperText('Stable identifier used on the booking request. Must match the partner system\'s own code where one exists — it is what gets reserved.')
                    ->required()
                    ->maxLength(64),
                Forms\Components\Textarea::make('description')
                    ->rows(2)
                    ->maxLength(1000)
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('max_adults')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(20)
                    ->default(2)
                    ->required(),
                Forms\Components\TextInput::make('max_children')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(20)
                    ->default(0)
                    ->required(),
                Forms\Components\TextInput::make('total_units')
                    ->label('Units of this type')
                    ->helperText('How many of this room exist. Availability is this minus the overlapping requests already in flight.')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(255)
                    ->required(),
                Forms\Components\TextInput::make('rate_per_night')
                    ->numeric()
                    ->minValue(0)
                    ->required(),
                Forms\Components\TextInput::make('currency')
                    ->default('NAD')
                    ->required()
                    ->maxLength(3),
                Forms\Components\Toggle::make('is_active')
                    ->helperText('Inactive room types are hidden from travelers and from availability.')
                    ->default(true),
                // A searchable multi-select rather than forty checkboxes: a
                // lodge ticking amenities knows what it is looking for and can
                // type it faster than it can find it. The category rides along
                // in each label, so "shower" narrows to the bathroom ones
                // without the list having to be grouped.
                Forms\Components\Select::make('amenities')
                    ->label('What this room has')
                    ->relationship('amenities', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Amenity $record): string => $record->label())
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->helperText('From the shared list, so two lodges describing the same thing describe it the same way. Anything missing is a request to us and one more entry for everybody.')
                    ->columnSpanFull(),
                Forms\Components\Placeholder::make('gallery_preview')
                    ->label('Current photos')
                    ->content(function (?RoomType $record): HtmlString {
                        $images = $record->gallery ?? [];

                        if (empty($images)) {
                            return new HtmlString('<span class="text-sm text-gray-500 dark:text-gray-400">No room photos yet — the plan falls back to the property gallery.</span>');
                        }

                        $thumbs = collect($images)
                            ->map(fn (string $path) => '<img src="'.e(Controller::resolveMediaUrl($path)).'" style="height: 120px; border-radius: 0.5rem; object-fit: cover;" />')
                            ->implode('');

                        return new HtmlString('<div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">'.$thumbs.'</div>');
                    })
                    ->visibleOn('edit')
                    ->columnSpanFull(),
                Forms\Components\FileUpload::make('gallery')
                    ->label('Room photos')
                    ->helperText('Pictures of this room specifically. The first one is the thumbnail in the trip plan.')
                    ->image()
                    ->multiple()
                    ->reorderable()
                    ->panelLayout('grid')
                    ->itemPanelAspectRatio(1)
                    ->imageEditor()
                    ->openable()
                    ->disk('r2')
                    ->directory('room-types/gallery')
                    ->fetchFileInformation(false)
                    ->columnSpanFull(),
                self::departures(),
            ])
            ->columns(2);
    }

    /**
     * The timetable this unit runs, where it runs one.
     *
     * It lives inside the unit rather than on a screen of its own because a
     * departure has no meaning apart from the thing it departs — and because a
     * nav item called "Departures" would be one every lodge in the panel has
     * to read past. Collapsed and empty by default, so a property that sells
     * nights sees one closed heading and never opens it. That is the rule this
     * whole design is judged by: unused things stay invisible.
     *
     * Deleting a departure that has seats on it is refused by the model — the
     * foreign keys cascade, so a delete would take the bookings and the prices
     * with it, below Eloquent and past the inventory write guard.
     */
    private static function departures(): Forms\Components\Section
    {
        return Forms\Components\Section::make('Departures')
            ->description('Only for something sold by the hour — a tour, a drive, a transfer. A room sold by the night has none, and this stays empty.')
            ->collapsed()
            ->columnSpanFull()
            ->schema([
                Forms\Components\Repeater::make('slots')
                    ->relationship()
                    ->hiddenLabel()
                    ->addActionLabel('Add a departure')
                    ->itemLabel(fn (array $state): ?string => trim(
                        substr((string) ($state['starts_at'] ?? ''), 0, 5).' '.($state['label'] ?? '')
                    ) ?: null)
                    // No drag handle: an hour axis is ordered by the clock, and
                    // a second order nobody can see would only be something to
                    // disagree with it.
                    ->reorderable(false)
                    ->schema([
                        Forms\Components\TimePicker::make('starts_at')
                            ->label('Departs at')
                            ->seconds(false)
                            ->required(),
                        Forms\Components\TextInput::make('duration_minutes')
                            ->label('Runs for')
                            ->helperText('Minutes. A drive that ends after midnight is still a duration, not a second date.')
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(1440)
                            ->default(180)
                            ->required(),
                        Forms\Components\TextInput::make('label')
                            ->label('Called')
                            ->helperText('Optional — a property running one departure a day has nothing to call it that the time does not already say.')
                            ->maxLength(255),
                        Forms\Components\Toggle::make('is_active')
                            ->label('On sale')
                            ->helperText('Switch a departure off rather than deleting it. What it already sold stays where it is.')
                            ->default(true),
                    ])
                    ->columns(2)
                    // One departure per unit per start time — a second one at
                    // 09:00 is a capacity question, not a timetable entry, and
                    // the database refuses it anyway. Said here first, because
                    // a unique-constraint violation is not something to show a
                    // lodge manager.
                    ->rules([
                        fn (): callable => function (string $attribute, mixed $value, callable $fail): void {
                            $times = [];

                            foreach (is_array($value) ? $value : [] as $slot) {
                                $time = substr((string) ($slot['starts_at'] ?? ''), 0, 5);

                                if ($time === '') {
                                    continue;
                                }

                                if (isset($times[$time])) {
                                    $fail("Two departures both start at {$time}. One departure per start time — if two run at once, that is more seats on the same one.");

                                    return;
                                }

                                $times[$time] = true;
                            }
                        },
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                Tables\Columns\ImageColumn::make('gallery.0')
                    ->label('')
                    ->height(40)
                    ->getStateUsing(fn (RoomType $record) => filled($record->gallery[0] ?? null)
                        ? Controller::resolveMediaUrl($record->gallery[0])
                        : null),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('code')
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('occupancy')
                    ->label('Sleeps')
                    ->getStateUsing(fn (RoomType $record) => $record->max_children > 0
                        ? "{$record->max_adults} + {$record->max_children}"
                        : (string) $record->max_adults),
                Tables\Columns\TextColumn::make('total_units')
                    ->label('Units'),
                Tables\Columns\TextColumn::make('rate_per_night')
                    ->label('Per night')
                    ->formatStateUsing(fn (RoomType $record) => "{$record->currency} ".number_format((float) $record->rate_per_night, 2)),
                Tables\Columns\TextColumn::make('amenities_count')
                    ->label('Amenities')
                    ->counts('amenities')
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('rate_per_night');
    }
}
