<?php

namespace App\Filament\Partner\Resources\CustomerResource\Pages;

use App\Filament\Partner\Resources\CustomerResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomers extends ListRecords
{
    protected static string $resource = CustomerResource::class;
}
