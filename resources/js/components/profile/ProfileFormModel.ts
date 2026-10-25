import type { MoneyFormat, Profile, ProfileSettings } from '@/api/types';
import { LOCALE_NAMES } from '@/i18n';

/**
 * The fields of a ФОП's profile (M-ProfileEdit), which its groups edit: the taxpayer, the address and contacts, the
 * invoices' currency and language, and the money formats (settings).
 */
export interface ProfileForm {
    full_name: string;
    full_name_en: string;
    tax_id: string;
    passport: boolean;
    registered_at: string;
    address: string;
    address_en: string;
    email: string;
    phone: string;
    default_currency: string;
    locale: string;
    display_format: MoneyFormat;
    invoice_format: MoneyFormat;
}

export type ProfileGroup = 'person' | 'contacts' | 'invoices' | 'formats';

/** The money formats, each shown on its sample. */
export const MONEY_FORMATS: { value: MoneyFormat; label: string }[] = [
    { value: 'space_dot', label: '2 851.99' },
    { value: 'plain_dot', label: '2851.99' },
    { value: 'comma_dot', label: '2,851.99' },
    { value: 'plain_comma', label: '2851,99' },
    { value: 'space_comma', label: '2 851,99' },
    { value: 'dot_comma', label: '2.851,99' },
];

export const PROFILE_CURRENCIES = ['USD', 'EUR', 'GBP', 'PLN', 'CHF', 'CAD', 'UAH'];

/** The languages of invoices, each named in itself. */
export const DOCUMENT_LOCALES = [
    { value: 'en', label: LOCALE_NAMES.en },
    { value: 'uk', label: LOCALE_NAMES.uk },
];

export function profileForm(profile: Profile | null, settings: ProfileSettings | null): ProfileForm {
    return {
        full_name: profile?.full_name ?? '',
        full_name_en: profile?.full_name_en ?? '',
        tax_id: '',
        passport: profile?.tax_id_kind === 'passport',
        registered_at: profile?.registered_at ?? '',
        address: profile?.address ?? '',
        address_en: profile?.address_en ?? '',
        email: profile?.email ?? '',
        phone: profile?.phone ?? '',
        default_currency: profile?.default_currency ?? 'USD',
        locale: profile?.locale ?? 'en',
        display_format: settings?.display.money_format ?? 'space_dot',
        invoice_format: settings?.invoice.money_format ?? 'plain_dot',
    };
}

/**
 * The profile's fields of a group (or of all of them), as the API takes them; the tax number goes only when typed.
 */
export function profileFields(form: ProfileForm, group: Exclude<ProfileGroup, 'formats'> | 'all'): Record<string, unknown> {
    const parts = {
        person: {
            full_name: form.full_name.trim(),
            full_name_en: form.full_name_en.trim() || null,
            ...(form.tax_id.trim() ? { tax_id: form.tax_id.replace(/\s/g, '').toUpperCase() } : {}),
            tax_id_kind: form.passport ? 'passport' : 'rnokpp',
            registered_at: form.registered_at || null,
        },
        contacts: {
            address: form.address.trim() || null,
            address_en: form.address_en.trim() || null,
            email: form.email.trim() || null,
            phone: form.phone.trim() || null,
        },
        invoices: { default_currency: form.default_currency, locale: form.locale },
    };

    return group === 'all' ? { ...parts.person, ...parts.contacts, ...parts.invoices } : parts[group];
}
