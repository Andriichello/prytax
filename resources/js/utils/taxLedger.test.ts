import { afterEach, describe, expect, it } from 'vitest';
import { locale } from '@/i18n';
import {
    filterFromQuery,
    LEDGER_STATUSES,
    LEDGER_TAXES,
    periodBadge,
    queryOfFilter,
    statusesBadge,
    tapQuarter,
    taxesBadge,
    toggleChoice,
    toggleOption,
} from '@/utils/taxLedger';

const now = { year: 2026, quarter: 4 };

describe('tapQuarter', () => {
    it.each([
        ['the whole year, a quarter chosen alone', [], 2, [2]],
        ['a quarter outside the range, the gap filled', [4], 2, [2, 3, 4]],
        ['a quarter before a range, which stretches to it', [3, 4], 1, [1, 2, 3, 4]],
        ['the first end trimmed', [2, 3, 4], 2, [3, 4]],
        ['the last end trimmed', [2, 3, 4], 4, [2, 3]],
        ['a quarter inside, the later part kept', [2, 3, 4], 3, [4]],
        ['the only one, the whole year again', [3], 3, []],
    ])('%s', (_, quarters, quarter, expected) => {
        expect(tapQuarter(quarters, quarter)).toEqual(expected);
    });
});

describe('toggleOption', () => {
    it('adds an option in the order of all of them, and removes a chosen one', () => {
        expect(toggleOption(['military_levy'], 'single_tax', LEDGER_TAXES)).toEqual(['single_tax', 'military_levy']);
        expect(toggleOption(['single_tax', 'military_levy'], 'single_tax', LEDGER_TAXES)).toEqual(['military_levy']);
    });

    it('chooses all of them again, when the last one is removed', () => {
        expect(toggleOption(['failed'], 'failed', LEDGER_STATUSES)).toEqual([...LEDGER_STATUSES]);
    });
});

describe('toggleChoice', () => {
    it('leaves none chosen, when the last one is removed, and adds in the order of all of them', () => {
        expect(toggleChoice(['military_levy'], 'military_levy', LEDGER_TAXES)).toEqual([]);
        expect(toggleChoice([], 'social_contribution', LEDGER_TAXES)).toEqual(['social_contribution']);
        expect(toggleChoice(['social_contribution'], 'single_tax', LEDGER_TAXES)).toEqual(['single_tax', 'social_contribution']);
    });
});

describe('the badges', () => {
    afterEach(() => {
        locale.value = 'uk';
    });

    it('name the period, the taxes and the statuses', () => {
        expect(periodBadge({ year: 2026, quarters: [4] })).toBe('IV кв. 2026');
        expect(periodBadge({ year: 2026, quarters: [2, 3, 4] })).toBe('II–IV кв. 2026');
        expect(periodBadge({ year: 2026, quarters: [] })).toBe('2026 · увесь рік');
        expect(taxesBadge([...LEDGER_TAXES])).toBe('Усі податки');
        expect(taxesBadge([])).toBe('Усі податки');
        expect(taxesBadge(['single_tax', 'military_levy'])).toBe('ЄП, ВЗ');
        expect(statusesBadge([...LEDGER_STATUSES])).toBe('Усі статуси');
        expect(statusesBadge(['failed', 'sent'])).toBe('Не пройшов, Надіслано');

        locale.value = 'en';

        expect(periodBadge({ year: 2026, quarters: [2, 3, 4] })).toBe('Q2–Q4 2026');
    });
});

describe('the URL of the filter', () => {
    it('keeps a range, the whole year, the taxes (none chosen too) and the statuses', () => {
        for (const filter of [
            { year: 2025, quarters: [2, 3, 4], taxes: ['social_contribution' as const], statuses: ['unpaid' as const, 'failed' as const] },
            { year: 2026, quarters: [], taxes: [...LEDGER_TAXES], statuses: [...LEDGER_STATUSES] },
            { year: 2026, quarters: [4], taxes: [], statuses: [...LEDGER_STATUSES] },
        ]) {
            expect(filterFromQuery(queryOfFilter(filter), now)).toEqual(filter);
        }
    });

    it('opens the current quarter without a query, a whole other year by its year, and an old link\'s quarter', () => {
        expect(filterFromQuery({}, now).quarters).toEqual([4]);
        expect(filterFromQuery({ year: '2025' }, now).quarters).toEqual([]);
        expect(filterFromQuery({ year: '2026', quarter: '2' }, now).quarters).toEqual([2]);
    });
});
