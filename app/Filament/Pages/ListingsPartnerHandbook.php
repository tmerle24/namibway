<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class ListingsPartnerHandbook extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Listings & Partner Handbook';

    protected static ?string $title = 'Listings & Partner Handbook';

    protected static ?string $navigationGroup = 'Documentation';

    protected static ?int $navigationSort = 5;

    protected static string $view = 'filament.pages.listings-partner-handbook';
}
