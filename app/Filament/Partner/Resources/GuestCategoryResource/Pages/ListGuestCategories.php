<?php

namespace App\Filament\Partner\Resources\GuestCategoryResource\Pages;

use App\Filament\Partner\Resources\GuestCategoryResource;
use App\Filament\Partner\Support\SelectedProperty;
use App\Services\Pricing\GuestCategoryDefaults;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListGuestCategories extends ListRecords
{
    protected static string $resource = GuestCategoryResource::class;

    public function getSubheading(): ?string
    {
        return app(SelectedProperty::class)->current()?->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Profiles, not configuration: setting a lodge up should be one
            // click and then adjustments, not an empty table to fill in.
            Actions\Action::make('addUsualSet')
                ->label('Add the usual set')
                ->icon('heroicon-m-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Add adults, children and infants')
                ->modalDescription('Adult 12 and over, child 3–11 at half price, infant under 3 free. Anything already here is left alone, and every one of them can be edited afterwards.')
                ->action(function (): void {
                    $property = app(SelectedProperty::class)->current();

                    if ($property === null) {
                        return;
                    }

                    GuestCategoryDefaults::ensureFor($property);

                    Notification::make()->success()->title('Guest types added')->send();
                }),
            Actions\CreateAction::make()->label('New guest type'),
        ];
    }
}
