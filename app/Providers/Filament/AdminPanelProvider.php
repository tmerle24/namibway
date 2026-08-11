<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ForceAdminLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
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
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandLogo(asset('images/namibway-logo-dark.png'))
            ->darkModeBrandLogo(asset('images/namibway-logo-light.png'))
            ->brandLogoHeight('59px')
            // The same mark the traveller-facing site declares first
            // (app.blade.php) — favicon.png is an older, darker variant that
            // predates the rebrand, so pointing at it gave the panels a
            // different tab icon from namibway.com.
            ->favicon(asset('favicon.ico'))
            // Tailwind's max-w scale stops at 7xl (80rem); '8xl' isn't a real
            // utility class, so it generates no CSS rule at all — which is
            // exactly what's wanted here, an uncapped-width <main>.
            ->maxContentWidth('max-w-8xl')
            ->colors([
                // Same brand rust as the partner panel (kaia-home.css --rust,
                // the brown of the logo's wordmark) — both panels are NamibWay,
                // so they read as one product rather than two tools that happen
                // to share a login.
                'primary' => Color::hex('#b5651d'),
            ])
            ->plugin(
                SpatieLaravelTranslatablePlugin::make()
                    ->defaultLocales(config('locales.supported'))
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->navigationGroups([
                NavigationGroup::make('Content'),
                NavigationGroup::make('Messaging')->collapsed(),
                NavigationGroup::make('Documentation')->collapsed(),
                NavigationGroup::make('Settings')->collapsed(),
            ])
            ->navigationItems([
                // Links out to the Scribe-generated static docs (see config/scribe.php,
                // routes/api.php) rather than a Filament page — there's no in-app content
                // to render, just the public /docs site partners/OTAs already use.
                // No ->sort(): items inside a group are ordered by label, see
                // App\Filament\Navigation\AlphabeticalNavigationManager.
                NavigationItem::make('API Documentation')
                    ->url(rtrim(config('scribe.base_url'), '/').'/docs', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-code-bracket')
                    ->group('Documentation'),
                // Opens the Roundcube webmail client (separate install, see WEBMAIL.md)
                // rather than an in-app inbox — real IMAP mailbox with attachments,
                // folders, and search instead of reimplementing those in Filament.
                NavigationItem::make('Emails')
                    ->url('https://webmail.namibway.com', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-envelope')
                    ->group('Messaging'),
            ])
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => view('filament.partials.release-version-badge')->render(),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partials.sticky-page-header')->render(),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partials.brand-logo')->render(),
            )
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
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
