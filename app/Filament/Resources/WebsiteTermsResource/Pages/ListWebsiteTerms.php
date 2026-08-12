<?php

namespace App\Filament\Resources\WebsiteTermsResource\Pages;

use App\Filament\Resources\WebsiteTermsResource;
use App\Models\WebsiteTerms;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWebsiteTerms extends ListRecords
{
    protected static string $resource = WebsiteTermsResource::class;

    /**
     * The scaffold is written on first visit rather than by a seeder, so a
     * fresh database and a deploy that has not run seeders both end up with
     * something to edit. Unpublished, because it has not been read by a lawyer.
     *
     * @return array<int, Actions\Action|Actions\CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            Actions\Action::make('scaffold')
                ->label('Write the first draft')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->visible(fn (): bool => WebsiteTerms::query()->doesntExist())
                ->requiresConfirmation()
                ->modalDescription('Creates an unpublished draft describing how the product works today. '
                    .'It is a starting point for a lawyer, not a reviewed contract.')
                ->action(fn () => WebsiteTerms::scaffoldIfEmpty()),
        ];
    }
}
