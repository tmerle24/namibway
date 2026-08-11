<?php

namespace App\Filament\Partner\Resources\ChargeResource\Pages;

use App\Filament\Partner\Resources\ChargeResource;
use App\Filament\Partner\Support\SelectedProperty;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateCharge extends CreateRecord
{
    protected static string $resource = ChargeResource::class;

    /**
     * The property comes from the panel, never from the form.
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
                ->body('Choose a property before adding a tax or fee.')
                ->send();

            throw new Halt;
        }

        $data['listing_id'] = $property->id;

        return $data;
    }
}
