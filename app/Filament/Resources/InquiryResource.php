<?php

namespace App\Filament\Resources;

use App\Enums\InquiryStatus;
use App\Filament\Resources\InquiryResource\Pages;
use App\Models\Inquiry;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class InquiryResource extends Resource
{
    protected static ?string $model = Inquiry::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    public static function getNavigationBadge(): ?string
    {
        $count = Inquiry::where('status', InquiryStatus::NwrPending)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Guest')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('listing_id')
                            ->relationship('listing', 'name')
                            ->disabled()
                            ->columnSpanFull(),
                        Forms\Components\TextInput::make('name')->disabled(),
                        Forms\Components\TextInput::make('email')->disabled(),
                        Forms\Components\TextInput::make('phone')->disabled(),
                        Forms\Components\TextInput::make('travel_dates')->disabled(),
                        Forms\Components\Textarea::make('message')->disabled()->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Status & Tracking')
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('status')
                            ->options(collect(InquiryStatus::cases())->mapWithKeys(
                                fn (InquiryStatus $s) => [$s->value => $s->label()]
                            ))
                            ->native(false),
                        Forms\Components\TextInput::make('connector_reference')
                            ->label('Connector / NWR Reference')
                            ->helperText('Booking reference from the PMS or NWR confirmation number'),
                        Forms\Components\Textarea::make('notes')
                            ->label('Team Notes')
                            ->helperText('Internal notes — not visible to the guest. Log NWR call outcomes here.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (InquiryStatus $state) => $state->color())
                    ->formatStateUsing(fn (InquiryStatus $state) => $state->label())
                    ->sortable(),
                Tables\Columns\TextColumn::make('listing.name')
                    ->label('Listing')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('phone')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('travel_dates')
                    ->label('Travel dates'),
                Tables\Columns\TextColumn::make('connector_reference')
                    ->label('Reference')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Received')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(InquiryStatus::cases())->mapWithKeys(
                        fn (InquiryStatus $s) => [$s->value => $s->label()]
                    ))
                    ->native(false),
            ])
            ->actions([
                Tables\Actions\Action::make('confirm')
                    ->label('Confirm')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Inquiry $r) => in_array($r->status, [InquiryStatus::Pending, InquiryStatus::NwrPending]))
                    ->form([
                        Forms\Components\TextInput::make('connector_reference')
                            ->label('Confirmation / NWR reference number')
                            ->required(),
                        Forms\Components\Textarea::make('notes')
                            ->label('Notes (optional)')
                            ->placeholder('e.g. Spoke to NWR Etosha camp on 27 Jul, 2 chalets available, confirmed by Maria.'),
                    ])
                    ->action(function (Inquiry $record, array $data): void {
                        $record->update([
                            'status' => InquiryStatus::Confirmed,
                            'connector_reference' => $data['connector_reference'],
                            'notes' => $data['notes'] ?? $record->notes,
                        ]);

                        Notification::make()
                            ->title('Inquiry confirmed')
                            ->body("Reference: {$data['connector_reference']}")
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Inquiry $r) => in_array($r->status, [InquiryStatus::Pending, InquiryStatus::NwrPending, InquiryStatus::Confirmed]))
                    ->requiresConfirmation()
                    ->form([
                        Forms\Components\Textarea::make('notes')
                            ->label('Reason / notes')
                            ->placeholder('e.g. NWR Etosha fully booked for requested dates.'),
                    ])
                    ->action(function (Inquiry $record, array $data): void {
                        $record->update([
                            'status' => InquiryStatus::Cancelled,
                            'notes' => $data['notes'] ?? $record->notes,
                        ]);

                        Notification::make()->title('Inquiry cancelled')->warning()->send();
                    }),

                Tables\Actions\EditAction::make()->label('Edit / Notes'),
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
            'index' => Pages\ListInquiries::route('/'),
            'edit' => Pages\EditInquiry::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
