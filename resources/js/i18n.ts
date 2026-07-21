import { createI18n } from 'vue-i18n';
import de from '@/lang/de.json';
import en from '@/lang/en.json';
import es from '@/lang/es.json';
import fr from '@/lang/fr.json';
import nl from '@/lang/nl.json';

export const i18n = createI18n({
    legacy: false,
    locale: 'en',
    fallbackLocale: 'en',
    messages: { en, de, nl, fr, es },
});
