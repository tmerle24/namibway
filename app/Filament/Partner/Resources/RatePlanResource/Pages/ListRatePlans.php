<?php

namespace App\Filament\Partner\Resources\RatePlanResource\Pages;

use App\Filament\Partner\Resources\RatePlanResource;
use App\Filament\Partner\Support\SelectedProperty;
use App\Services\Pricing\RatePlanProfiles;
use Filament\Actions;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListRatePlans extends ListRecords
{
    protected static string $resource = RatePlanResource::class;

    public function getSubheading(): ?string
    {
        return app(SelectedProperty::class)->current()?->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Profiles, not configuration: a lodge should recognise its own
            // business in a sentence and press one button, rather than work
            // out what a pricing strategy is before it can enter a price.
            Actions\Action::make('addProfile')
                ->label('Set up the usual rates')
                ->icon('heroicon-m-sparkles')
                ->color('gray')
                ->modalHeading('How does this property sell?')
                ->modalDescription('This creates working rate plans you can then edit. Anything already here is left alone.')
                ->modalSubmitActionLabel('Create them')
                ->form([
                    Radio::make('profile')
                        ->hiddenLabel()
                        ->options(collect(RatePlanProfiles::all())
                            ->map(fn (array $profile): string => $profile['name'])
                            ->all())
                        ->descriptions(collect(RatePlanProfiles::all())
                            ->map(fn (array $profile): string => $profile['description'])
                            ->all())
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $property = app(SelectedProperty::class)->current();

                    if ($property === null) {
                        return;
                    }

                    $created = RatePlanProfiles::apply($property, (string) $data['profile']);

                    Notification::make()
                        ->success()
                        ->title($created === 0 ? 'Nothing to add' : $created.' '.str('rate plan')->plural($created).' created')
                        ->body($created === 0
                            ? 'This property already has those rates.'
                            : 'Enter the prices on the Rates screen.')
                        ->send();
                }),

            Actions\CreateAction::make()->label('New rate plan'),
        ];
    }
}
