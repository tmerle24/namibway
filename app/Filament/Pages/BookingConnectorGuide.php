<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class BookingConnectorGuide extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-link';

    protected static ?string $navigationLabel = 'Booking Connector Guide';

    protected static ?string $title = 'Booking Connector Guide';

    protected static ?string $navigationGroup = 'Documentation';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.booking-connector-guide';
}
