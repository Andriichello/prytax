/**
 * Податки's filters (design.md §16.2, §8.5): one year with a contiguous range of its quarters, where none is the whole
 * year; any of the taxes and any of the statuses, where none chosen means all of them, as all chosen does. They live
 * in the URL: `year`, `quarters` (`2-4`, `4`, or `all` for the whole year), `taxes` and `statuses` (comma-separated,
 * left out when all are chosen, empty when none is).
 */

import type { TaxLedgerStatus, TaxType } from '@/api/types';
import { t } from '@/i18n';
import { quarterLabel } from '@/utils/dates';
import { TAX_PAYMENT_STATUSES, TAX_TYPES } from '@/utils/statuses';

/** The taxes in the order of the filter and of the badge. */
export const LEDGER_TAXES: readonly TaxType[] = ['single_tax', 'military_levy', 'social_contribution'];

/** The statuses in the order of the filter, of the badge and of a month's list. */
export const LEDGER_STATUSES: readonly TaxLedgerStatus[] = ['unpaid', 'failed', 'sent', 'paid'];

export interface TaxLedgerFilter {
    year: number;
    /** A contiguous range, ascending; empty is the whole year. */
    quarters: number[];
    /** None chosen means all of them. */
    taxes: TaxType[];
    /** None chosen means all of them. */
    statuses: TaxLedgerStatus[];
}

/** The quarters from one to another, ascending. */
function range(first: number, last: number): number[] {
    return Array.from({ length: last - first + 1 }, (_, index) => first + index);
}

/**
 * The quarters after a tap on one: from the whole year, just it; outside the range, the range stretched to it, the
 * gap filled; the only one chosen, the whole year again; an end of the range, that end trimmed; inside the range, the
 * part after it kept.
 */
export function tapQuarter(quarters: number[], quarter: number): number[] {
    if (quarters.length === 0) {
        return [quarter];
    }

    const first = quarters[0];
    const last = quarters[quarters.length - 1];

    if (!quarters.includes(quarter)) {
        return range(Math.min(first, quarter), Math.max(last, quarter));
    }

    if (quarters.length === 1) {
        return [];
    }

    if (quarter === first) {
        return quarters.slice(1);
    }

    if (quarter === last) {
        return quarters.slice(0, -1);
    }

    return quarters.filter((item) => item > quarter);
}

/**
 * The options after a tap on one: a chosen one leaves, the last one too, and a new one joins, in the order of `all`.
 */
export function toggleChoice<T>(chosen: readonly T[], option: T, all: readonly T[]): T[] {
    return chosen.includes(option) ? chosen.filter((item) => item !== option) : all.filter((item) => item === option || chosen.includes(item));
}

/** Whether a choice shows every option: all of them chosen, or none. */
export function choosesAll(chosen: readonly unknown[], all: readonly unknown[]): boolean {
    return chosen.length === 0 || chosen.length === all.length;
}

/** «IV кв.», «II–IV кв.» (Q4, Q2–Q4). */
export function quarterRangeLabel(quarters: number[]): string {
    const numerals = (quarter: number): string => [
        t('core.quarterNumerals.q1'),
        t('core.quarterNumerals.q2'),
        t('core.quarterNumerals.q3'),
        t('core.quarterNumerals.q4'),
    ][quarter - 1] ?? String(quarter);

    return quarters.length === 1
        ? quarterLabel(quarters[0])
        : t('core.quarterRange', { first: numerals(quarters[0]), last: numerals(quarters[quarters.length - 1]) });
}

/** The period's badge: «IV кв. 2026», «II–IV кв. 2026», «2026 · увесь рік». */
export function periodBadge(filter: Pick<TaxLedgerFilter, 'year' | 'quarters'>): string {
    return filter.quarters.length === 0
        ? t('shell.filters.wholeYearBadge', { year: filter.year })
        : `${quarterRangeLabel(filter.quarters)} ${filter.year}`;
}

/** «Усі податки» (all of them chosen, or none), or the chosen ones: «ЄП, ВЗ». */
export function taxesBadge(taxes: readonly TaxType[]): string {
    return choosesAll(taxes, LEDGER_TAXES) ? t('accounting.taxes.allTaxes') : taxes.map((tax) => TAX_TYPES[tax]).join(', ');
}

/** A status's name: «Не сплачено», «Не пройшов», «Надіслано», «Сплачено». */
export function ledgerStatusLabel(status: TaxLedgerStatus): string {
    return status === 'unpaid' ? t('core.taxPaymentStatus.unpaid') : TAX_PAYMENT_STATUSES[status].label;
}

/** «Усі статуси» (all of them chosen, or none), or the chosen ones: «Не пройшов, Надіслано». */
export function statusesBadge(statuses: readonly TaxLedgerStatus[]): string {
    return choosesAll(statuses, LEDGER_STATUSES) ? t('accounting.taxes.allStatuses') : statuses.map(ledgerStatusLabel).join(', ');
}

/** The default filter: the current quarter, every tax, every status. */
export function defaultFilter(now: { year: number; quarter: number }): TaxLedgerFilter {
    return { year: now.year, quarters: [now.quarter], taxes: [...LEDGER_TAXES], statuses: [...LEDGER_STATUSES] };
}

/** The options of a comma-separated list, which are known, in their own order; none known is all of them. */
function listOf<T extends string>(value: unknown, all: readonly T[]): T[] {
    const chosen = typeof value === 'string' ? value.split(',') : [];
    const known = all.filter((item) => chosen.includes(item));

    return known.length > 0 ? known : [...all];
}

/**
 * The filter of the URL. Without `quarters`, a link names the current quarter of the current year, or a whole other
 * year; an old link's `quarter` names one quarter.
 */
export function filterFromQuery(query: Readonly<Record<string, unknown>>, now: { year: number; quarter: number }): TaxLedgerFilter {
    const year = Number(query.year) || now.year;
    const value = typeof query.quarters === 'string' ? query.quarters : null;
    const legacy = Number(query.quarter);

    let quarters: number[];

    if (value === 'all') {
        quarters = [];
    } else if (value !== null && /^[1-4](-[1-4])?$/.test(value)) {
        const [first, last = first] = value.split('-').map(Number);
        quarters = range(Math.min(first, last), Math.max(first, last));
    } else if (legacy >= 1 && legacy <= 4) {
        quarters = [legacy];
    } else {
        quarters = year === now.year ? [now.quarter] : [];
    }

    return {
        year,
        quarters,
        taxes: query.taxes === '' ? [] : listOf(query.taxes, LEDGER_TAXES),
        statuses: query.statuses === '' ? [] : listOf(query.statuses, LEDGER_STATUSES),
    };
}

/** The URL's query of the filter. */
export function queryOfFilter(filter: TaxLedgerFilter): Record<string, string | undefined> {
    const { quarters } = filter;

    return {
        year: String(filter.year),
        quarters: quarters.length === 0
            ? 'all'
            : quarters.length === 1 ? String(quarters[0]) : `${quarters[0]}-${quarters[quarters.length - 1]}`,
        taxes: filter.taxes.length === LEDGER_TAXES.length ? undefined : filter.taxes.join(','),
        statuses: filter.statuses.length === LEDGER_STATUSES.length ? undefined : filter.statuses.join(','),
    };
}
