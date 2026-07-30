<?php

namespace App\Filament\Resources\PartnerResource\Pages;

use App\Filament\Resources\PartnerResource;
use App\Models\Partner;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Pages\ListRecords\Concerns\Translatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;

class ListPartners extends ListRecords
{
    use Translatable;

    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\LocaleSwitcher::make(),
            Actions\Action::make('send_outreach_batch')
                ->label('Send outreach batch')
                ->icon('heroicon-o-paper-airplane')
                ->color('warning')
                ->modalHeading('Send "claim your listing" emails')
                ->modalDescription('Sends outreach emails to scraped partners who have an email address and haven\'t been contacted, claimed, or opted out yet. Keep batches small and spread runs over days/weeks to avoid being flagged as spam.')
                ->form([
                    Forms\Components\TextInput::make('limit')
                        ->label('How many to send now')
                        ->numeric()
                        ->default(10)
                        ->minValue(1)
                        ->maxValue(100)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $eligible = self::eligibleForOutreachQuery()->count();

                    Artisan::call('namibway:send-claim-emails', [
                        '--limit' => (int) $data['limit'],
                        '--force' => true,
                    ]);

                    $sentCount = min((int) $data['limit'], $eligible);

                    Notification::make()
                        ->title($sentCount > 0 ? "Sent {$sentCount} outreach email(s)" : 'Nothing to send')
                        ->body($sentCount > 0 ? null : 'No eligible partners (need an email and claim link, and not yet contacted/claimed/declined).')
                        ->success()
                        ->send();
                })
                ->visible(fn () => self::eligibleForOutreachQuery()->exists()),
            Actions\CreateAction::make(),
        ];
    }

    /** @return Builder<Partner> */
    private static function eligibleForOutreachQuery(): Builder
    {
        return Partner::whereNotNull('email')
            ->whereNotNull('claim_token')
            ->whereNull('claim_token_sent_at')
            ->whereNull('claimed_at')
            ->whereNull('claim_rejected_at');
    }
}
