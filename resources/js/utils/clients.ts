import type { ClientAddress } from '@/api/types';
import { locale, type Locale } from '@/i18n';

/**
 * Helpers for clients: the name of a country, the address as text, initials for the avatar, and an abbreviation to
 * suggest.
 */

const regions = new Map<Locale, Intl.DisplayNames | null>();

/**
 * The names of countries in the language of the screens.
 */
function regionNames(): Intl.DisplayNames | null {
    if (!regions.has(locale.value)) {
        try {
            regions.set(locale.value, new Intl.DisplayNames([locale.value], { type: 'region' }));
        } catch {
            regions.set(locale.value, null);
        }
    }

    return regions.get(locale.value) ?? null;
}

/**
 * A country's name in the language of the screens by its ISO code: "US" → "США", "United States" (the code itself,
 * when unknown).
 */
export function countryName(code: string | null | undefined): string {
    if (!code) {
        return '';
    }

    try {
        return regionNames()?.of(code.toUpperCase()) ?? code;
    } catch {
        return code;
    }
}

const LEGAL_FORMS = /^(llc|l\.l\.c\.|inc\.?|ltd\.?|limited|gmbh|ag|sa|s\.a\.|bv|b\.v\.|oy|ab|as|sp\.|z|o\.o\.|s\.r\.o\.|corp\.?|co\.?|plc|ou|oü)$/i;

/**
 * A client's address as its invoices print it, line by line: its text, or the parts of an older archive (the lines,
 * then the city, the state and the postal code).
 */
export function clientAddressText(address: ClientAddress | null | undefined): string {
    if (!address) {
        return '';
    }

    if (typeof address.text === 'string') {
        return address.text.trim();
    }

    return [...(address.lines ?? []), [address.city, address.state, address.zip].filter(Boolean).join(' ')]
        .map((line) => (line ?? '').trim())
        .filter(Boolean)
        .join('\n');
}

/**
 * Two letters for a client's avatar: the first letters of its first two words, legal forms left out.
 */
export function clientInitials(name: string): string {
    const words = name.split(/[\s,]+/).filter((word) => word && !LEGAL_FORMS.test(word));
    const letters = words.length > 1 ? `${words[0]![0]}${words[1]![0]}` : (words[0] ?? name).slice(0, 2);

    return letters.toUpperCase();
}

/** The longest abbreviation of a client: the prefix of its invoice numbers. */
export const ABBREVIATION_MAX = 10;

/**
 * An abbreviation to suggest for a client, as design.md §5 describes it: the initials of up to four words of the name,
 * legal forms left out, or the first three letters of a one-word name — Latin letters and digits, upper case
 * ("Brightline Labs LLC" → "BL", "Nordwave GmbH" → "NOR"). Empty, when the name has no Latin letters or digits.
 */
export function suggestAbbreviation(name: string): string {
    const words = name.split(/[\s,]+/)
        .filter((word) => word && !LEGAL_FORMS.test(word))
        .map((word) => normalizeAbbreviation(word))
        .filter(Boolean);
    const abbreviation = words.length > 1 ? words.slice(0, 4).map((word) => word[0]).join('') : (words[0] ?? '').slice(0, 3);

    return abbreviation.slice(0, ABBREVIATION_MAX);
}

/**
 * An abbreviation as it is stored: upper case, Latin letters and digits only ("bl-2" → "BL2").
 */
export function normalizeAbbreviation(value: string): string {
    return value.toUpperCase().replace(/[^A-Z0-9]/g, '');
}

/**
 * The countries clients usually come from, for the form's list (the rest can be typed as a code).
 */
export const COMMON_COUNTRIES = ['US', 'GB', 'DE', 'PL', 'NL', 'CH', 'CA', 'IE', 'EE', 'LT', 'LV', 'CZ', 'FR', 'ES', 'IT', 'SE', 'DK', 'NO', 'FI', 'AT', 'BE', 'PT', 'CY', 'SG', 'AE', 'IL', 'AU', 'UA'];
