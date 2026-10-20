/**
 * Money and rate formatting, the same as the API's MoneyFormatter: amounts arrive as decimal strings ("3892.50") and
 * are formatted as strings, never through floating point.
 *
 * The app's format is the profile setting `display.money_format` (`1 234.99` by default); invoices have their own.
 */

import type { MoneyFormat } from '@/api/types';

/** The narrow no-break space, which groups thousands in the "space" formats (as on the PDFs). */
export const GROUP_SPACE = '\u202F';

/** The minus sign of outgoing money and negative balances (U+2212), not a hyphen. */
export const MINUS = '\u2212';

/** The no-break space between an amount and its currency. */
const CURRENCY_SPACE = '\u00A0';

const SEPARATORS: Record<MoneyFormat, { group: string; decimal: string }> = {
    plain_dot: { group: '', decimal: '.' },
    space_dot: { group: GROUP_SPACE, decimal: '.' },
    comma_dot: { group: ',', decimal: '.' },
    plain_comma: { group: '', decimal: ',' },
    space_comma: { group: GROUP_SPACE, decimal: ',' },
    dot_comma: { group: '.', decimal: ',' },
};

let appFormat: MoneyFormat = 'space_dot';

/**
 * Set the format of the app's amounts (the current profile's `display.money_format`).
 */
export function setMoneyFormat(format: MoneyFormat | null | undefined): void {
    appFormat = format && format in SEPARATORS ? format : 'space_dot';
}

export function moneyFormat(): MoneyFormat {
    return appFormat;
}

export interface AmountOptions {
    /** Prefix positive amounts with "+" (incoming money). */
    signed?: boolean;
    format?: MoneyFormat;
}

/**
 * Format a decimal string ("-1234.5", "41.8710") with the app's separators, keeping its decimals as they are.
 */
export function formatAmount(value: string | number | null | undefined, options: AmountOptions = {}): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    const text = String(value).trim();
    const negative = text.startsWith('-');
    const [whole = '0', fraction] = text.replace(/^[-+]/, '').split('.');
    const { group, decimal } = SEPARATORS[options.format ?? appFormat];

    const grouped = group ? whole.replace(/\B(?=(\d{3})+(?!\d))/g, group) : whole;
    const formatted = fraction === undefined ? grouped : `${grouped}${decimal}${fraction}`;
    const isZero = /^0*\.?0*$/.test(text.replace(/^[-+]/, ''));

    if (negative && !isZero) {
        return MINUS + formatted;
    }

    return options.signed && !isZero ? `+${formatted}` : formatted;
}

/**
 * The sign of a currency in the app: `₴` for hryvnias, the ISO code otherwise.
 */
export function currencySymbol(currency: string): string {
    return currency.toUpperCase() === 'UAH' ? '₴' : currency.toUpperCase();
}

/**
 * An amount with its currency after it: "3 892.50 USD", "1 840 135.05 ₴".
 */
export function formatMoney(value: string | number | null | undefined, currency: string, options: AmountOptions = {}): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return `${formatAmount(value, options)}${CURRENCY_SPACE}${currencySymbol(currency)}`;
}

/**
 * An NBU rate: "1 USD = 41.8710 ₴".
 */
export function formatRate(rate: string | null | undefined, currency: string): string {
    if (!rate) {
        return '';
    }

    return `1${CURRENCY_SPACE}${currency.toUpperCase()} = ${formatAmount(rate)}${CURRENCY_SPACE}₴`;
}

/**
 * A whole percentage of a part in a total ("80 %"), rounded, but never 100 % before the total is reached, nor 0 %
 * once something is there.
 */
export function percentOf(part: string | number, total: string | number): number {
    const whole = Number(total);
    const share = Number(part);

    if (!whole || share <= 0) {
        return 0;
    }

    const percent = Math.round((share / whole) * 100);

    return share < whole ? Math.min(99, Math.max(1, percent)) : percent;
}

/**
 * Compare two decimal strings exactly (by cents), for signs and thresholds that must not suffer rounding.
 */
export function compareAmounts(a: string, b: string): number {
    const cents = (value: string): bigint => {
        const negative = value.trim().startsWith('-');
        const [whole = '0', fraction = ''] = value.trim().replace(/^[-+]/, '').split('.');
        const result = BigInt(whole || '0') * 100n + BigInt((fraction + '00').slice(0, 2));

        return negative ? -result : result;
    };

    const difference = cents(a) - cents(b);

    return difference === 0n ? 0 : difference > 0n ? 1 : -1;
}

/**
 * Turn what a person typed ("2 851,99", "2,851.99") into the API's decimal string, or null when it isn't a number.
 * The last separator is the decimal one, like the API's MoneyFormatter::parse().
 */
export function parseAmount(input: string): string | null {
    let value = input.trim().replace(/[\s\u00A0\u202F\u2009']/g, '').replace(MINUS, '-');

    if (value === '' || !/^-?[0-9.,]+$/.test(value)) {
        return null;
    }

    const lastSeparator = Math.max(value.lastIndexOf('.'), value.lastIndexOf(','));

    if (lastSeparator !== -1) {
        value = `${value.slice(0, lastSeparator).replace(/[.,]/g, '')}.${value.slice(lastSeparator + 1)}`;
    }

    return /^-?[0-9]*\.?[0-9]+$/.test(value) ? value : null;
}

/**
 * A decimal string as a whole number of its smallest units at a scale: units('12.5', 2) is 1250n.
 */
function units(value: string, scale: number): bigint {
    const text = value.trim();
    const negative = text.startsWith('-') || text.startsWith(MINUS);
    const [whole = '0', fraction = ''] = text.replace(/^[-+−]/, '').split('.');
    const result = BigInt(whole || '0') * 10n ** BigInt(scale) + BigInt((fraction + '0'.repeat(scale)).slice(0, scale) || '0');

    return negative ? -result : result;
}

/**
 * Units back to a decimal string with `scale` decimals.
 */
function fromUnits(value: bigint, scale: number): string {
    const negative = value < 0n;
    const digits = (negative ? -value : value).toString().padStart(scale + 1, '0');

    return `${negative ? '-' : ''}${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
}

/**
 * The exact sum of amounts, with two decimals.
 */
export function sumAmounts(...values: (string | null | undefined)[]): string {
    return fromUnits(values.reduce<bigint>((sum, value) => sum + (value ? units(value, 2) : 0n), 0n), 2);
}

/**
 * a − b, exactly, with two decimals.
 */
export function subtractAmounts(a: string, b: string): string {
    return fromUnits(units(a, 2) - units(b, 2), 2);
}

/**
 * quantity × price, rounded half up to two decimals like the API (an invoice item's amount).
 */
export function multiplyAmounts(quantity: string, price: string): string {
    const product = units(quantity, 2) * units(price, 2);
    const negative = product < 0n;
    const absolute = negative ? -product : product;
    const rounded = (absolute + 50n) / 100n;

    return fromUnits(negative ? -rounded : rounded, 2);
}

/**
 * a × b for decimals of any scale (an amount by a rate), rounded half up to two decimals.
 */
export function multiplyDecimals(a: string, b: string): string {
    const scaleOf = (value: string): number => value.trim().split('.')[1]?.length ?? 0;
    const scale = scaleOf(a) + scaleOf(b);
    const product = units(a, scaleOf(a)) * units(b, scaleOf(b));

    if (scale <= 2) {
        return fromUnits(product * 10n ** BigInt(2 - scale), 2);
    }

    const shift = 10n ** BigInt(scale - 2);
    const negative = product < 0n;
    const rounded = ((negative ? -product : product) + shift / 2n) / shift;

    return fromUnits(negative ? -rounded : rounded, 2);
}

const COMPACT_SIGNS: Record<string, string> = { USD: '$', EUR: '€', GBP: '£', UAH: '₴', PLN: 'zł' };

/**
 * An amount with a short currency sign after it, for compact rows (handoff §5): "27.20 $", "3 631.75 €".
 */
export function formatCompact(value: string | null | undefined, currency: string): string {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return `${formatAmount(value)}${CURRENCY_SPACE}${COMPACT_SIGNS[currency.toUpperCase()] ?? currency.toUpperCase()}`;
}
