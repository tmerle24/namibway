<?php

namespace App\Providers\Filament;

use App\Http\Controllers\Partner\SelectPropertyController;
use App\Http\Middleware\ForceAdminLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\SpatieLaravelTranslatablePlugin;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PartnerPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('partner')
            ->path('partner')
            ->login()
            ->brandLogo(asset('images/namibway-logo-dark.png'))
            ->darkModeBrandLogo(asset('images/namibway-logo-light.png'))
            ->brandLogoHeight('80px')
            ->favicon(asset('favicon.png'))
            ->colors([
                'primary' => Color::Teal,
            ])
            ->plugin(
                SpatieLaravelTranslatablePlugin::make()
                    ->defaultLocales(config('locales.supported'))
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partials.sticky-page-header')->render(),
            )
            // A partner with several properties — NWR is one partner with about
            // twenty camps — picks which one the lodge-facing screens show. The
            // partial renders nothing for a partner with one property or none.
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): string => view('filament.partner.partials.property-switcher')->render(),
            )
            // Registered on the panel rather than in routes/web.php so the post
            // runs the panel's own middleware. The same route in the web group
            // would drag the traveller-facing stack — Inertia, currency, locale
            // — through a form submission that only writes a session key.
            ->authenticatedRoutes(fn () => Route::post('property', SelectPropertyController::class)
                ->name('property.select'))
            ->discoverResources(in: app_path('Filament/Partner/Resources'), for: 'App\\Filament\\Partner\\Resources')
            ->discoverPages(in: app_path('Filament/Partner/Pages'), for: 'App\\Filament\\Partner\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Partner/Widgets'), for: 'App\\Filament\\Partner\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                ForceAdminLocale::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
