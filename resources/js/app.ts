import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { i18n } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeCurrency } from '@/lib/currency';
import { initializeFlashToast } from '@/lib/flashToast';
import { initializeLocale } from '@/lib/locale';

const appName = import.meta.env.VITE_APP_NAME || 'NamibWay';
const slogan = 'The smartest way to experience Namibia.';

createInertiaApp({
    title: (title) =>
        title ? `${title} - ${appName}` : `${appName} — ${slogan}`,
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name === 'Dashboard':
            case name === 'ListingDetail':
            case name === 'ListingEdit':
            case name === 'Terms':
            case name === 'TripPlan':
            case name === 'Claim':
            case name === 'ClaimDecline':
            case name === 'partner/ActionResult':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
    withApp: (app, { page }) => {
        app.use(i18n);
        initializeLocale(page.props.locale as string | undefined);
        initializeCurrency(
            page.props.currency as string | undefined,
            page.props.currencyRates as Record<string, number> | undefined,
        );
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// Register the service worker (production only, so it never intercepts
// requests against the Vite dev server).
if (import.meta.env.PROD && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js');
    });
}

// Expose the *actually visible* viewport height as a CSS custom property.
// CSS's own svh/dvh/lvh units are each a guess at what a given mobile
// browser's chrome (address bar, bottom toolbar) leaves on screen, and that
// guess has repeatedly been wrong in practice — iPhone Chrome and Safari
// both reported viewport-unit values taller than what's really visible with
// their chrome on screen (see kaia-home.css's mobile Kaia-tab layout,
// --app-vh's only consumer). window.visualViewport (or innerHeight as a
// fallback for older WebKit/PWA/native-wrapper contexts without it) is the
// browser telling us the real, current pixel count instead — no guessing
// about which viewport-unit semantics a given engine implements.
function updateAppViewportHeight() {
    const height = window.visualViewport?.height ?? window.innerHeight;
    document.documentElement.style.setProperty('--app-vh', `${height}px`);
}
updateAppViewportHeight();
window.visualViewport?.addEventListener('resize', updateAppViewportHeight);
window.addEventListener('resize', updateAppViewportHeight);
window.addEventListener('orientationchange', updateAppViewportHeight);
