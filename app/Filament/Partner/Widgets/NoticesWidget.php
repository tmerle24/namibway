<?php

namespace App\Filament\Partner\Widgets;

use App\Filament\Partner\Support\PropertyNotices;
use App\Filament\Partner\Support\SelectedProperty;
use App\Models\Listing;
use Filament\Widgets\Widget;

/**
 * The dashboard's notice board: what needs the operator today, above the
 * arrivals.
 *
 * It replaces a strip pinned above every screen, which cost the whole panel a
 * few pixels of scroll and fought the sticky page header — and it can carry
 * more than one subject, which a banner cannot. What appears here is decided by
 * PropertyNotices; the widget itself renders and knows nothing.
 *
 * It disappears entirely when there is nothing to say, so a property that is
 * set up and live has a dashboard with no advice on it at all.
 */
class NoticesWidget extends Widget
{
    protected static string $view = 'filament.partner.widgets.notices';

    protected int|string|array $columnSpan = 'full';

    /** Above the arrivals board, which sorts at 1. */
    protected static ?int $sort = 0;

    /** Rendered with the page for the same reason as the arrivals widget: satellite links. */
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        if (blank(auth()->user()?->partner_id)) {
            return false;
        }

        return app(PropertyNotices::class)->for(app(SelectedProperty::class)->current()) !== [];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        /** @var Listing|null $property */
        $property = app(SelectedProperty::class)->current();

        return [
            'notices' => app(PropertyNotices::class)->for($property),
        ];
    }
}
