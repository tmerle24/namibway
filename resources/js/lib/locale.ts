import { router } from '@inertiajs/vue3';
import { i18n } from '@/i18n';

function applyLocale(locale: string | undefined): void {
    if (!locale) {
        return;
    }

    i18n.global.locale.value = locale as 'en' | 'de' | 'nl' | 'fr' | 'es';
    document.documentElement.lang = locale;
}

export function initializeLocale(initialLocale: string | undefined): void {
    applyLocale(initialLocale);

    router.on('success', (event) => {
        const locale = (event as CustomEvent).detail?.page?.props?.locale as
            | string
            | undefined;
        applyLocale(locale);
    });
}
