import { describe, expect, it } from 'vitest';
import { documentDate, purposeValues, purposeWarnings, renderPurpose, unknownPlaceholders } from '@/utils/invoiceDocument';

describe('documentDate', () => {
    it('writes a date as the invoice of the language does', () => {
        expect(documentDate('2026-10-01', 'en')).toBe('Oct 1, 2026');
        expect(documentDate('2026-10-01', 'uk')).toBe('1 жовтня 2026');
        expect(documentDate('2026-02-28', 'uk')).toBe('28 лютого 2026');
        expect(documentDate(null, 'en')).toBe('');
    });
});

describe('renderPurpose', () => {
    it('fills the placeholders, and keeps one without a value', () => {
        const purpose = renderPurpose('Payment for {services} by the invoice No. {invoice.number} under {contract.number}', {
            services: 'IT services',
            'invoice.number': 'BL-2026-10-31-1',
            'contract.number': null,
        });

        expect(purpose).toBe('Payment for IT services by the invoice No. BL-2026-10-31-1 under {contract.number}');
    });
});

describe('unknownPlaceholders', () => {
    it('finds each placeholder outside of the whitelist once', () => {
        expect(unknownPlaceholders('{invoice.number} {invoice.total} {iban} {invoice.total}')).toEqual(['invoice.total', 'iban']);
    });
});

describe('purposeWarnings', () => {
    it.each([
        ['SWIFT characters in dollars', "Payment for IT services (O'Brien+Co)", 'USD', []],
        ['longer than 140 characters in euros', 'Payment for IT services '.repeat(6), 'EUR', ['payment_purpose_too_long']],
        ['a number sign in dollars', 'Payment by the invoice № BL-1', 'USD', ['payment_purpose_charset']],
        ['anything in hryvnias', `${'№ 1 '.repeat(50)}`, 'UAH', []],
    ])('warns of %s as the API does', (_case, purpose, currency, warnings) => {
        expect(purposeWarnings(purpose, currency)).toEqual(warnings);
    });
});

describe('purposeValues', () => {
    const sample = {
        number: 'BL-2026-10-31-1',
        issuedAt: '2026-10-31',
        periodStart: '2026-10-01',
        periodEnd: '2026-10-31',
        contract: { name: 'Service Agreement', number: null, started_at: '2026-09-01', subject: null },
        client: { name: 'Brightline', legal_name: 'Brightline Labs LLC', name_uk: null, legal_name_uk: 'TOV Brightline' },
        profile: { full_name: 'Myrchuk Mykola (uk)', full_name_en: 'Myrchuk Mykola' },
    };
    const texts = { payment_purpose: '', act_note: '', services: 'services' };

    it('fills the values in the language, with the built-in services for a contract without a subject', () => {
        expect(purposeValues(sample, 'uk', texts)).toEqual({
            services: 'services',
            'invoice.number': 'BL-2026-10-31-1',
            'invoice.date': '31 жовтня 2026',
            'period.start': '1 жовтня 2026',
            'period.end': '31 жовтня 2026',
            'contract.name': 'Service Agreement',
            'contract.number': null,
            'contract.date': '1 вересня 2026',
            'client.name': 'TOV Brightline',
            'profile.name': 'Myrchuk Mykola (uk)',
        });
        expect(purposeValues(sample, 'en', texts)).toMatchObject({ 'client.name': 'Brightline Labs LLC', 'profile.name': 'Myrchuk Mykola' });
    });
});
