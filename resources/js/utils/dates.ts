import { locale, t } from '@/i18n';

/**
 * Dates of the API are `YYYY-MM-DD` strings in Kyiv's calendar; they are split as text, never parsed into a Date, so
 * no time zone moves them by a day. Dates are written дд.мм.рррр in every language; the names of months come from
 * `Intl` in the language of the screens, and the names of quarters from its dictionary.
 */

export interface DateParts {
    year: number;
    month: number;
    day: number;
}

/**
 * Split a `YYYY-MM-DD` (or the date of an ISO date-time) into numbers.
 */
export function dateParts(date: string): DateParts {
    const [year = '0', month = '1', day = '1'] = date.slice(0, 10).split('-');

    return { year: Number(year), month: Number(month), day: Number(day) };
}

const pad = (value: number): string => String(value).padStart(2, '0');

/** "14.10.2026" */
export function formatDate(date: string | null | undefined): string {
    if (!date) {
        return '';
    }

    const { year, month, day } = dateParts(date);

    return `${pad(day)}.${pad(month)}.${year}`;
}

/**
 * A date typed the Ukrainian way, as `YYYY-MM-DD`: "14.10.2026", "4.1.2026", "14/10/2026", "14-10-2026" or
 * "14102026"; null when it isn't a whole, real date.
 */
export function parseDisplayDate(text: string): string | null {
    const value = text.trim();
    const match = /^(\d{1,2})[./\-\s](\d{1,2})[./\-\s](\d{4})$/.exec(value) ?? /^(\d{2})(\d{2})(\d{4})$/.exec(value);

    if (!match) {
        return null;
    }

    const [day, month, year] = [Number(match[1]), Number(match[2]), Number(match[3])];
    const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();

    if (year < 1900 || month < 1 || month > 12 || day < 1 || day > daysInMonth) {
        return null;
    }

    return `${year}-${pad(month)}-${pad(day)}`;
}

/** "14.10" */
export function formatDayMonth(date: string | null | undefined): string {
    if (!date) {
        return '';
    }

    const { month, day } = dateParts(date);

    return `${pad(day)}.${pad(month)}`;
}

/** "7 липня", "7 July" */
export function formatDayMonthWords(date: string | null | undefined): string {
    if (!date) {
        return '';
    }

    const { month, day } = dateParts(date);

    return `${day} ${monthNameGenitive(month)}`;
}

const monthFormats = new Map<string, { alone: Intl.DateTimeFormat; withDay: Intl.DateTimeFormat }>();

/**
 * The formats of a month's name in the language of the screens: alone ("жовтень") and with a day ("7 жовтня").
 */
function monthFormat(): { alone: Intl.DateTimeFormat; withDay: Intl.DateTimeFormat } {
    if (!monthFormats.has(locale.value)) {
        monthFormats.set(locale.value, {
            alone: new Intl.DateTimeFormat(locale.value, { month: 'long', timeZone: 'UTC' }),
            withDay: new Intl.DateTimeFormat(locale.value, { day: 'numeric', month: 'long', timeZone: 'UTC' }),
        });
    }

    return monthFormats.get(locale.value)!;
}

/**
 * The first day of a month (1–12), for naming the month; null when there is no such month.
 */
const dayOfMonth = (month: number): Date | null => (month >= 1 && month <= 12 ? new Date(Date.UTC(2000, month - 1, 1)) : null);

/** "жовтень", "October": a month inside a sentence, written as its language writes it there */
export function monthNameInText(month: number): string {
    const date = dayOfMonth(month);

    return date ? monthFormat().alone.format(date) : '';
}

/** "Жовтень", "October" */
export function monthName(month: number): string {
    const name = monthNameInText(month);

    return name.charAt(0).toLocaleUpperCase(locale.value) + name.slice(1);
}

/** "жовтня", "October": the month of a day, as in "7 липня" */
export function monthNameGenitive(month: number): string {
    const date = dayOfMonth(month);

    return date ? monthFormat().withDay.formatToParts(date).find((part) => part.type === 'month')?.value ?? '' : '';
}

/** "Жовтень 2026", "October 2026" */
export function formatMonthYear(year: number, month: number): string {
    return `${monthName(month)} ${year}`;
}

/** "IV кв.", "Q4" */
export function quarterLabel(quarter: number): string {
    return [t('core.quarters.q1'), t('core.quarters.q2'), t('core.quarters.q3'), t('core.quarters.q4')][quarter - 1] ?? String(quarter);
}

/** "IV квартал", "Q4" */
export function quarterName(quarter: number): string {
    return [t('core.quarterNames.q1'), t('core.quarterNames.q2'), t('core.quarterNames.q3'), t('core.quarterNames.q4')][quarter - 1]
        ?? String(quarter);
}

/** The quarter (1–4) of a month or a date. */
export function quarterOf(monthOrDate: number | string): number {
    const month = typeof monthOrDate === 'number' ? monthOrDate : dateParts(monthOrDate).month;

    return Math.ceil(month / 3);
}

/**
 * A period: "01.10 – 14.10.2026" within a year, "16.12.2025 – 15.01.2026" across years.
 */
export function formatPeriod(start: string | null | undefined, end: string | null | undefined): string {
    if (!start || !end) {
        return formatDate(start ?? end);
    }

    return dateParts(start).year === dateParts(end).year
        ? `${formatDayMonth(start)} – ${formatDate(end)}`
        : `${formatDate(start)} – ${formatDate(end)}`;
}

/**
 * Today in Kyiv, as `YYYY-MM-DD` (the app's calendar, whatever the device's time zone).
 */
export function today(): string {
    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Kyiv' }).format(new Date());
}

/**
 * Whole days from one date to another (negative when `to` is earlier).
 */
export function daysBetween(from: string, to: string): number {
    const utc = (date: string): number => {
        const { year, month, day } = dateParts(date);

        return Date.UTC(year, month - 1, day);
    };

    return Math.round((utc(to) - utc(from)) / 86_400_000);
}

/**
 * The current year and quarter in Kyiv.
 */
export function currentQuarter(): { year: number; quarter: number } {
    const { year, month } = dateParts(today());

    return { year, quarter: quarterOf(month) };
}

/**
 * The Kyiv calendar date of a timestamp of the API (`2026-10-20T18:48:04.000000Z` → `2026-10-20`).
 */
export function kyivDate(timestamp: string | null | undefined): string {
    if (!timestamp) {
        return '';
    }

    return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Kyiv' }).format(new Date(timestamp));
}
