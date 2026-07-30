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
            case name === 'ListingDetail':
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
