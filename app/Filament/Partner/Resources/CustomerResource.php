<?php

namespace App\Filament\Partner\Resources;

use App\Filament\Partner\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The people this property sells to.
 *
 * A booking system without this screen can answer "who is arriving on
 * Thursday" and not "has this person stayed here before" — and the second
 * question is the one asked at a reception desk while somebody is standing
 * there. Everything a customer knows is derived from their stays, so the
 * screen is a search, a history and a place for notes, and nothing else.
 *
 * Scoped to the partner rather than the selected property, unlike the rest of
 * the panel: NWR is one partner with twenty camps, and a guest who stayed at
 * one of them is their guest, not that camp's.
 */
class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Customers';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return filled(auth()->user()?->partner_id);
    }

    public static function canCreate(): bool
    {
        // A customer is made by taking a booking, never by filling in a form
        // first. Two ways to create one is how a property ends up with two
        // records for the same person and the notes on the wrong one.
        return false;
    }

    /** @return Builder<Customer> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Customer> $query */
        $query = parent::getEloquentQuery()
            ->where('partner_id', auth()->user()->partner_id ?? 0)
            ->withCount('reservations')
            ->withMax('reservations', 'check_in');

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make()
                ->description('Correcting a customer here changes the record, not the bookings — '
                    .'a stay keeps the name and address it was taken under.')
                ->schema([
                    Grid::make(2)->schema([
                        TextInput::make('name')->required()->maxLength(180),
                        TextInput::make('email')
                            ->email()
                            ->maxLength(180)
                            ->helperText('Left empty for somebody who booked at the desk without one.')
                            // Lowercased by the model on the way in, which is
                            // what makes this unique key case-insensitive.
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule
                                ->where('partner_id', auth()->user()->partner_id ?? 0)),
                        TextInput::make('phone')->tel()->maxLength(60),
                        TextInput::make('country')
                            ->label('Country')
                            ->maxLength(2)
                            ->helperText('Two letters, e.g. DE.'),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->weight('semibold'),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                // Both are searchable, because a guest reads out "081 234 5678"
                // and the desk has it stored as "+264 81 234 5678".
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('phone', 'like', "%{$search}%")
                        ->orWhere('phone_normalised', 'like', '%'.preg_replace('/[^\d]/', '', $search).'%'))
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('reservations_count')
                    ->label('Stays')
                    ->sortable(),
                Tables\Columns\TextColumn::make('reservations_max_check_in')
                    ->label('Last arrival')
                    ->date('D M Y')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('user_id')
                    ->label('Account')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-minus-small')
                    ->tooltip('Has a NamibWay account, so they stay the same person after an email change.'),
            ])
            ->defaultSort('reservations_max_check_in', 'desc')
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->emptyStateHeading('No customers yet')
            ->emptyStateDescription('A customer is created by the first booking taken for them.');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'view' => Pages\ViewCustomer::route('/{record}'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}
