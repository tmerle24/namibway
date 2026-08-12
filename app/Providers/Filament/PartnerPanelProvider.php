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
        $panel = $panel
            ->id('partner')
            // A queued job that can partly fail needs somewhere to say so —
            // see the notifications migration. Polling is off: these arrive
            // while somebody is already looking at the panel, and a background
            // request every few seconds on every open tab is a poor trade for
            // seeing it a minute sooner.
            ->databaseNotifications()
            ->path('partner');

        // The lodge-facing booking system is sold in its own right, so it gets
        // its own address rather than looking like a sub-page of a travel
        // site. Unset — local development, CI, and production until the DNS
        // record exists — the panel answers on whatever host serves the app,
        // exactly as before. See config/booking.php and DEPLOYMENT.md.
        if (filled(config('booking.panel_domain'))) {
            $panel = $panel->domain((string) config('booking.panel_domain'));
        }

        return $panel
            ->login()
            ->brandLogo(asset('images/namibway-logo-dark.png'))
            ->darkModeBrandLogo(asset('images/namibway-logo-light.png'))
            // The sidebar header offers 272px of width (20rem less px-6), and
            // the logo is 733×171 — so anything over ~63px tall gets its width
            // clamped and the wordmark squeezed. 56px also leaves the h-16
            // header a little air. brand-logo.blade.php guards the ratio if a
            // container is ever narrower still.
            ->brandLogoHeight('56px')
            // The same mark the traveller-facing site declares first
            // (app.blade.php) — favicon.png is an older, darker variant that
            // predates the rebrand, so pointing at it gave the panels a
            // different tab icon from namibway.com.
            ->favicon(asset('favicon.ico'))
            ->colors([
                // The brand rust from the homepage palette (kaia-home.css
                // --rust), which is the brown of the logo's wordmark — the
                // lodge-facing panel is sold as NamibWay's own product, so it
                // wears the brand colour rather than a generic teal.
                'primary' => Color::hex('#b5651d'),
            ])
            ->plugin(
                SpatieLaravelTranslatablePlugin::make()
                    ->defaultLocales(config('locales.supported'))
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partials.sticky-page-header')->render(),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partials.brand-logo')->render(),
            )
            // The panel has its own address and that address gets shared, so
            // it needs its own link preview — Filament's layout carries none.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partner.partials.link-preview')->render(),
            )
            // On the panel and not on a screen, because every page a property
            // prints has the same problem: Filament's shell is what has a
            // position on paper, and the content is what gets clipped. A
            // printed passenger list is carried to a vehicle and a printed
            // calendar goes on the office wall.
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partner.partials.print-styles')->render(),
            )
            // A demo tenant has to be unmistakable from any screen, including
            // one someone photographs or prints. BODY_START rather than a page
            // header hook, because a seeded booking must not look real on the
            // login screen or on a page nobody thought to decorate either.
            ->renderHook(
                PanelsRenderHook::BODY_START,
                fn (): string => view('filament.partner.partials.demo-banner')->render(),
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
