import type { Client, InvoiceLocale, InvoiceTexts, InvoiceWarning, Profile } from '@/api/types';

/**
 * The payment purpose of an invoice, as the API's PaymentPurposeRenderer renders it (design.md §4.1), for the
 * previews of the forms: the whitelisted placeholders filled in the invoice's language, and the warnings of a
 * reference, which SWIFT or SEPA can't carry in a foreign currency.
 */

/** The placeholders a template may use, in the order the forms offer them. */
export const PURPOSE_PLACEHOLDERS = [
    'services',
    'invoice.number',
    'invoice.date',
    'period.start',
    'period.end',
    'contract.name',
    'contract.number',
    'contract.date',
    'client.name',
    'profile.name',
] as const;

export type PurposePlaceholder = (typeof PURPOSE_PLACEHOLDERS)[number];

/** The values of the placeholders; one without a value stays as it is. */
export type PurposeValues = Partial<Record<PurposePlaceholder, string | null>>;

/** What a preview of a payment purpose knows of its invoice: a saved draft, the form of one, or a sample. */
export interface PurposeSample {
    number: string | null;
    issuedAt: string | null;
    periodStart: string | null;
    periodEnd: string | null;
    contract: { name: string; number: string | null; started_at: string; subject: string | null } | null;
    client: Pick<Client, 'name' | 'legal_name' | 'name_uk' | 'legal_name_uk'> | null;
    profile: Pick<Profile, 'full_name' | 'full_name_en'> | null;
}

/** The length of a SWIFT (MT103 field 70) or SEPA payment reference. */
export const REFERENCE_LENGTH = 140;

/** The characters of a SWIFT payment reference (the X character set). */
const REFERENCE_CHARSET = /^[A-Za-z0-9/\-?:().,'+ ]*$/;

const PLACEHOLDER = /\{([a-z_]+(?:\.[a-z_]+)?)\}/g;

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

/**
 * A date of an invoice's document in its language: `Oct 31, 2026` in English, `31.10.2026` in Ukrainian.
 */
export function documentDate(date: string | null | undefined, locale: InvoiceLocale): string {
    if (!date) {
        return '';
    }

    const [year = '', month = '', day = ''] = date.slice(0, 10).split('-');

    return locale === 'uk' ? `${day}.${month}.${year}` : `${MONTHS[Number(month) - 1]} ${Number(day)}, ${year}`;
}

/**
 * Fill the template's placeholders with the values; one without a value stays as it is.
 */
export function renderPurpose(template: string, values: PurposeValues): string {
    return template.replace(PLACEHOLDER, (match, name: string) => values[name as PurposePlaceholder] ?? match);
}

/**
 * The placeholders of the template, which aren't in the whitelist.
 */
export function unknownPlaceholders(template: string): string[] {
    const names = [...template.matchAll(PLACEHOLDER)].map((match) => match[1]!);

    return [...new Set(names.filter((name) => !(PURPOSE_PLACEHOLDERS as readonly string[]).includes(name)))];
}

/**
 * The warnings of a payment purpose in the currency: none in hryvnias; in another currency, when it is longer than
 * a SWIFT or SEPA reference, or has characters outside of the SWIFT set.
 */
export function purposeWarnings(purpose: string, currency: string | null): InvoiceWarning[] {
    if (!currency || currency === 'UAH') {
        return [];
    }

    return [
        ...([...purpose].length > REFERENCE_LENGTH ? ['payment_purpose_too_long' as const] : []),
        ...(REFERENCE_CHARSET.test(purpose) ? [] : ['payment_purpose_charset' as const]),
    ];
}

/**
 * The values of the placeholders for the sample in the language, as the API fills them: the contract's subject or
 * the built-in word for services, dates in the language, and the parties' names in it.
 */
export function purposeValues(sample: PurposeSample, locale: InvoiceLocale, texts: InvoiceTexts | null): PurposeValues {
    const { client, contract, profile } = sample;
    const ukrainianClient = locale === 'uk' ? client?.legal_name_uk || client?.name_uk : null;

    return {
        services: contract?.subject || texts?.services || null,
        'invoice.number': sample.number,
        'invoice.date': documentDate(sample.issuedAt, locale) || null,
        'period.start': documentDate(sample.periodStart, locale) || null,
        'period.end': documentDate(sample.periodEnd, locale) || null,
        'contract.name': contract?.name ?? null,
        'contract.number': contract?.number || null,
        'contract.date': contract ? documentDate(contract.started_at, locale) : null,
        'client.name': client ? ukrainianClient || client.legal_name || client.name : null,
        'profile.name': profile ? (locale === 'uk' ? profile.full_name : profile.full_name_en || profile.full_name) : null,
    };
}
