<?php

namespace App\Filament\Partner\Resources;

use App\Enums\InquiryStatus;
use App\Filament\Partner\Resources\InquiryResource\Pages;
use App\Models\Inquiry;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InquiryResource extends Resource
{
    protected static ?string $model = Inquiry::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox';

    protected static ?string $navigationLabel = 'Booking Requests';

    protected static ?string $breadcrumb = 'Booking Requests';

    /** @return Builder<Inquiry> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('listing', fn (Builder $q) => $q->where('partner_id', auth()->user()?->partner_id))
            ->with('listing')
            ->latest();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()
            ->whereIn('status', [InquiryStatus::Pending->value, InquiryStatus::OnRequest->value])
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Guest')
                    ->schema([
                        Infolists\Components\TextEntry::make('name')->label('Name'),
                        Infolists\Components\TextEntry::make('email')->label('Email')->copyable(),
                        Infolists\Components\TextEntry::make('phone')->label('Phone')->placeholder('—'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Stay')
                    ->schema([
                        Infolists\Components\TextEntry::make('listing.name')->label('Listing'),
                        Infolists\Components\TextEntry::make('check_in')->label('Check-in')->date('D, d M Y')->placeholder('—'),
                        Infolists\Components\TextEntry::make('check_out')->label('Check-out')->date('D, d M Y')->placeholder('—'),
                        Infolists\Components\TextEntry::make('adults')->label('Adults'),
                        Infolists\Components\TextEntry::make('children')->label('Children'),
                        Infolists\Components\TextEntry::make('room_type_code')->label('Room type')->placeholder('—'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Booking')
                    ->schema([
                        Infolists\Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn (InquiryStatus $state): string => $state->color()),
                        Infolists\Components\TextEntry::make('connector_reference')->label('Booking reference')->placeholder('—'),
                        Infolists\Components\TextEntry::make('total_amount')
                            ->label('Total')
                            ->money(fn (Inquiry $record): string => $record->currency ?? 'NAD')
                            ->placeholder('—'),
                    ])
                    ->columns(3),

                Infolists\Components\Section::make('Message')
                    ->schema([
                        Infolists\Components\TextEntry::make('message')->placeholder('No message')->columnSpanFull(),
                    ])
                    ->visible(fn (Inquiry $record): bool => filled($record->message)),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Guest')->searchable(),
                Tables\Columns\TextColumn::make('listing.name')->label('Listing'),
                Tables\Columns\TextColumn::make('check_in')->label('Check-in')->date('d M Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('check_out')->label('Check-out')->date('d M Y')->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (InquiryStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('created_at')->label('Received')->since()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(InquiryStatus::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInquiries::route('/'),
            'view' => Pages\ViewInquiry::route('/{record}'),
        ];
    }
}
