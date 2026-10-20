/**
 * Helpers for clients: the name of a country, and initials for the avatar.
 */

const regions = (() => {
    try {
        return new Intl.DisplayNames(['uk'], { type: 'region' });
    } catch {
        return null;
    }
})();

/**
 * A country's Ukrainian name by its ISO code: "US" → "США" (the code itself, when unknown).
 */
export function countryName(code: string | null | undefined): string {
    if (!code) {
        return '';
    }

    try {
        return regions?.of(code.toUpperCase()) ?? code;
    } catch {
        return code;
    }
}

const LEGAL_FORMS = /^(llc|l\.l\.c\.|inc\.?|ltd\.?|limited|gmbh|ag|sa|s\.a\.|bv|b\.v\.|oy|ab|as|sp\.|z|o\.o\.|s\.r\.o\.|corp\.?|co\.?|plc|ou|oü)$/i;

/**
 * Two letters for a client's avatar: the first letters of its first two words, legal forms left out.
 */
export function clientInitials(name: string): string {
    const words = name.split(/[\s,]+/).filter((word) => word && !LEGAL_FORMS.test(word));
    const letters = words.length > 1 ? `${words[0]![0]}${words[1]![0]}` : (words[0] ?? name).slice(0, 2);

    return letters.toUpperCase();
}

/**
 * The countries clients usually come from, for the form's list (the rest can be typed as a code).
 */
export const COMMON_COUNTRIES = ['US', 'GB', 'DE', 'PL', 'NL', 'CH', 'CA', 'IE', 'EE', 'LT', 'LV', 'CZ', 'FR', 'ES', 'IT', 'SE', 'DK', 'NO', 'FI', 'AT', 'BE', 'PT', 'CY', 'SG', 'AE', 'IL', 'AU', 'UA'];
