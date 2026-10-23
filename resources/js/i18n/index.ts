import { ref } from 'vue';
import { EN } from '@/i18n/en';

/**
 * The languages of the screens. Ukrainian is the source: texts are written in Ukrainian and passed through `t()`,
 * which looks them up in the English dictionary (`i18n/en/*.ts`) while English is chosen.
 */
export type Locale = 'uk' | 'en';

export const LOCALES: Locale[] = ['uk', 'en'];

/** The name of each language in itself, for the switch. */
export const LOCALE_NAMES: Record<Locale, string> = {
    uk: 'Українська', // i18n-ignore
    en: 'English',
};

const STORAGE_KEY = 'prytax.locale';

/**
 * The language last chosen on this device, for the screens before signing in.
 */
function storedLocale(): Locale {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);

        return stored === 'en' ? 'en' : 'uk';
    } catch {
        return 'uk';
    }
}

/**
 * The language of the screens: the signed-in user's (`locale` of `auth/me`), and before signing in the one last chosen
 * on this device.
 */
export const locale = ref<Locale>(storedLocale());

/**
 * Whether the value is one of the languages of the screens.
 */
export function isLocale(value: unknown): value is Locale {
    return LOCALES.includes(value as Locale);
}

/**
 * Switch the screens to the language, and remember it on this device.
 */
export function setLocale(value: Locale): void {
    locale.value = value;
    markDocument();

    try {
        window.localStorage.setItem(STORAGE_KEY, value);
    } catch {
        // A private window keeps no storage: the language lasts until the page is reloaded
    }
}

/**
 * A Ukrainian text of the screens in the current language, with its `{name}` placeholders filled in:
 * t('Наступний номер · {client}', { client: 'Brightline' }). A text without its English falls back to Ukrainian.
 * One Ukrainian text, which English says in two ways, carries a context after `||`, which Ukrainian doesn't show:
 * t('Надходження||одне') is "Надходження" or "Receipt", while t('Надходження') is "Receipts". It reads the current
 * language, so templates and computed values, which call it, follow a switch.
 */
export function t(text: string, params?: Record<string, string | number | null | undefined>): string {
    const ukrainian = text.split('||')[0]!;
    const translated = locale.value === 'en' ? EN[text] ?? ukrainian : ukrainian;

    if (!params) {
        return translated;
    }

    return translated.replace(/\{(\w+)\}/g, (placeholder, name: string) => (params[name] == null ? placeholder : String(params[name])));
}

/**
 * Name the language of the page (`<html lang>`) for screen readers and the browser's spelling and hyphenation.
 */
function markDocument(): void {
    if (typeof document !== 'undefined') {
        document.documentElement.lang = locale.value;
    }
}

markDocument();
