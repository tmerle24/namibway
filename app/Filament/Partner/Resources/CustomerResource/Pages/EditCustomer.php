<?php

namespace App\Filament\Partner\Resources\CustomerResource\Pages;

use App\Filament\Partner\Resources\CustomerResource;
use Filament\Resources\Pages\EditRecord;

/**
 * Correcting what the property knows about somebody.
 *
 * Its own screen rather than a field that a booking can rewrite: a customer
 * record is everything learned over several stays, and one mistyped surname on
 * a Tuesday must not be able to change it. Nothing here touches the bookings —
 * a stay keeps the name and address it was taken under.
 */
class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;
}
