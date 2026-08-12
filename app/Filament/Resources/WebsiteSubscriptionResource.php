<?php

namespace App\Filament\Resources;

use App\Enums\SubscriptionStatus;
use App\Filament\Resources\WebsiteSubscriptionResource\Pages;
use App\Models\WebsiteSubscription;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Who is a website customer, and who has asked to be.
 *
 * The whole of billing, for now: somebody reads this list, invoices the ones
 * that are new, and presses Activate when the money arrives. That is the
 * decision recorded in PROJECT_STATUS.md — the state machine gets built now and
 * the collection is plugged in behind it once there is a provider for this
 * market. Nothing on this screen names a price, because the price is a property
 * of the offer and not of one customer.
 */
class WebsiteSubscriptionResource extends Resource
{
    protected static ?string $model = WebsiteSubscription::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'Website Subscriptions';

    protected static ?string $navigationGroup = 'Settings';

    protected static ?string $modelLabel = 'website subscription';

    /** New orders, so nobody has to remember to look. */
    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()
            ->where('status', SubscriptionStatus::Requested->value)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Textarea::make('note')
                ->label('Note')
                ->helperText('Why it is where it is — what the owner asked for, or why we suspended it.')
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('partner.name')->label('Business')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('partner.email')->label('Email')->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (SubscriptionStatus $state): string => $state->label())
                    ->color(fn (SubscriptionStatus $state): string => $state->color()),
                Tables\Columns\TextColumn::make('requested_at')->label('Ordered')->since()->sortable(),
                Tables\Columns\TextColumn::make('activated_at')->label('Customer since')->date('j M Y')->placeholder('—'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options(SubscriptionStatus::class),
            ])
            ->actions([
                Tables\Actions\Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->visible(fn (WebsiteSubscription $record): bool => ! $record->isEntitled())
                    ->requiresConfirmation()
                    ->modalDescription('They become a customer: the website button unlocks in their panel '
                        .'and stays unlocked until this is suspended or cancelled. Invoice them first.')
                    ->action(fn (WebsiteSubscription $record) => $record->activate()),

                Tables\Actions\Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-m-pause')
                    ->color('danger')
                    ->visible(fn (WebsiteSubscription $record): bool => $record->isEntitled())
                    ->requiresConfirmation()
                    ->modalDescription('The button locks again. The website itself stays exactly as it is '
                        .'— suspending takes the entitlement away, not their content.')
                    ->form([
                        Textarea::make('note')->label('Why')->rows(2)->maxLength(500),
                    ])
                    ->action(fn (WebsiteSubscription $record, array $data) => $record->suspend($data['note'] ?? null)),

                Tables\Actions\Action::make('cancel')
                    ->label('Cancel')
                    ->icon('heroicon-m-x-mark')
                    ->color('gray')
                    ->visible(fn (WebsiteSubscription $record): bool => $record->status !== SubscriptionStatus::Cancelled)
                    ->requiresConfirmation()
                    ->modalDescription('Over. The row stays — it is the record of a customer we had.')
                    ->form([
                        Textarea::make('note')->label('Why')->rows(2)->maxLength(500),
                    ])
                    ->action(fn (WebsiteSubscription $record, array $data) => $record->cancel($data['note'] ?? null)),

                Tables\Actions\EditAction::make(),
            ])
            ->defaultSort('requested_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebsiteSubscriptions::route('/'),
            'edit' => Pages\EditWebsiteSubscription::route('/{record}/edit'),
        ];
    }
}
