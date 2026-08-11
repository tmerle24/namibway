<?php

namespace App\Filament\Partner\Resources\RatePlanResource\Pages;

use App\Filament\Partner\Resources\RatePlanResource;
use App\Filament\Partner\Support\SelectedProperty;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateRatePlan extends CreateRecord
{
    protected static string $resource = RatePlanResource::class;

    /**
     * The property comes from the panel, never from the form. A listing id in
     * a posted payload is how one partner would price another's rooms.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws Halt
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $property = app(SelectedProperty::class)->current();

        if ($property === null) {
            Notification::make()
                ->danger()
                ->title('No property selected')
                ->body('Choose a property before creating a rate plan.')
                ->send();

            throw new Halt;
        }

        $data['listing_id'] = $property->id;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
