import { createI18n } from 'vue-i18n';
import en from '@/lang/en';
import hi from '@/lang/hi';
import hinglish from '@/lang/hinglish';

export const SUPPORTED_LOCALES = ['en', 'hi', 'hinglish'] as const;
export type AppLocale = (typeof SUPPORTED_LOCALES)[number];

function initialLocale(): AppLocale {
    // `document` does not exist during SSR (Node) — default to English there.
    if (typeof document === 'undefined') {
        return 'en';
    }
    const fromHtml = document.documentElement.lang as AppLocale;
    if (SUPPORTED_LOCALES.includes(fromHtml)) {
        return fromHtml;
    }
    return 'en';
}

export const i18n = createI18n({
    legacy: false,
    locale: initialLocale(),
    fallbackLocale: 'en',
    messages: { en, hi, hinglish },
});

export function setI18nLocale(locale: AppLocale): void {
    i18n.global.locale.value = locale;
    if (typeof document !== 'undefined') {
        document.documentElement.setAttribute('lang', locale);
    }
}
