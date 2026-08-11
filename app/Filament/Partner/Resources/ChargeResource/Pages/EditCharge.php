<?php

namespace App\Filament\Partner\Resources\ChargeResource\Pages;

use App\Filament\Partner\Resources\ChargeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing a charge changes what the *next* booking is charged and nothing
 * about the ones already taken — every stay froze its own copy. That is the
 * point of the frozen rows, and it is what makes a VAT change safe to make on
 * the day it takes effect.
 */
class EditCharge extends EditRecord
{
    protected static string $resource = ChargeResource::class;

    /**
     * @return array<int, DeleteAction>
     */
    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
