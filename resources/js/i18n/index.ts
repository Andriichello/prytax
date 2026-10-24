import { ref } from 'vue';
import en from '@/i18n/en';
import type { KeysOf, Messages, PluralForms } from '@/i18n/types';
import uk from '@/i18n/uk';

/**
 * The languages of the screens: each has its dictionary (`i18n/{locale}/*.ts`, with the keys of the Ukrainian one)
 * and its name in itself, for the switch. A language is added with its folder and a line here; the API answers in it
 * once `api-core.locales` has it too.
 */
const LANGUAGES = {
    uk: { name: 'Українська', messages: uk }, // i18n-ignore
    en: { name: 'English', messages: en },
} satisfies Record<string, { name: string; messages: Messages }>;

export type Locale = keyof typeof LANGUAGES;

export const LOCALES = Object.keys(LANGUAGES) as Locale[];

/** The language of a device, which hasn't chosen one, and of a text, which another language lacks. */
export const DEFAULT_LOCALE: Locale = 'uk';

/** The name of each language in itself, for the switch. */
export const LOCALE_NAMES = Object.fromEntries(LOCALES.map((code) => [code, LANGUAGES[code].name])) as Record<Locale, string>;

/** A key of the dictionaries: 'invoices.editor.issueDate'. */
export type MessageKey = KeysOf<typeof uk>;

const STORAGE_KEY = 'prytax.locale';

/**
 * Whether the value is one of the languages of the screens.
 */
export function isLocale(value: unknown): value is Locale {
    return LOCALES.includes(value as Locale);
}

/**
 * The language last chosen on this device, for the screens before signing in.
 */
function storedLocale(): Locale {
    try {
        const stored = window.localStorage.getItem(STORAGE_KEY);

        return isLocale(stored) ? stored : DEFAULT_LOCALE;
    } catch {
        return DEFAULT_LOCALE;
    }
}

/**
 * The language of the screens: the signed-in user's (`locale` of `auth/me`), and before signing in the one last chosen
 * on this device.
 */
export const locale = ref<Locale>(storedLocale());

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
 * The entry of a key in a language's dictionary: a text, the forms of a plural, or nothing.
 */
function entryOf(language: Locale, key: string): string | PluralForms | undefined {
    let entry: Messages[string] | undefined = LANGUAGES[language].messages;

    for (const part of key.split('.')) {
        entry = typeof entry === 'object' ? (entry as Messages)[part] : undefined;
    }

    return entry as string | PluralForms | undefined;
}

const pluralRules = new Map<Locale, Intl.PluralRules>();

/**
 * The form of a plural for a count, by the rules of the language.
 */
function pluralOf(language: Locale, forms: PluralForms, count: number): string | undefined {
    if (!pluralRules.has(language)) {
        pluralRules.set(language, new Intl.PluralRules(language));
    }

    return forms[pluralRules.get(language)!.select(count)] ?? forms.other;
}

/**
 * A text of the screens in the current language, with its `{name}` placeholders filled in:
 * t('invoices.list.nextNumber', { number: 14 }). A plural takes its form by `count`:
 * t('core.counts.invoices', { count: 2 }) is "2 інвойси" or "2 invoices". A key, which the language lacks, falls back
 * to Ukrainian. It reads the current language, so templates and computed values, which call it, follow a switch.
 */
export function t(key: MessageKey, params?: Record<string, string | number | null | undefined>): string {
    const language = entryOf(locale.value, key) === undefined ? DEFAULT_LOCALE : locale.value;
    const entry = entryOf(language, key);
    const text = (typeof entry === 'object' ? pluralOf(language, entry, Number(params?.count)) : entry) ?? key;

    if (!params) {
        return text;
    }

    return text.replace(/\{(\w+)\}/g, (placeholder, name: string) => (params[name] == null ? placeholder : String(params[name])));
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
