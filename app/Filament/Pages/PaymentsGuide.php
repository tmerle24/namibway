<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * How the money side works, for the people who operate and sell it.
 *
 * Two audiences, and the page is written for both at once because they ask the
 * same questions in a different order: staff who have to understand what the
 * system does, and staff who have to explain it to a lodge owner on the phone.
 * Splitting them would produce two pages that drift, and the sales answer that
 * is not also the true answer is the one that gets us into trouble.
 *
 * It repeats what `PAYMENTS.md` says rather than linking to it, on purpose:
 * the person who needs this is in the admin panel with a partner on the phone,
 * not in a code repository.
 */
class PaymentsGuide extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Payments Guide';

    protected static ?string $title = 'Payments Guide';

    protected static ?string $navigationGroup = 'Documentation';

    protected static string $view = 'filament.pages.payments-guide';
}
