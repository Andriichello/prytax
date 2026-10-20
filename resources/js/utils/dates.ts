/**
 * Dates of the API are `YYYY-MM-DD` strings in Kyiv's calendar; they are split as text, never parsed into a Date, so
 * no time zone moves them by a day.
 */

const MONTHS = [
    'Січень', 'Лютий', 'Березень', 'Квітень', 'Травень', 'Червень',
    'Липень', 'Серпень', 'Вересень', 'Жовтень', 'Листопад', 'Грудень',
];

/** Months in the genitive case, for "7 липня". */
const MONTHS_GENITIVE = [
    'січня', 'лютого', 'березня', 'квітня', 'травня', 'червня',
    'липня', 'серпня', 'вересня', 'жовтня', 'листопада', 'грудня',
];

/** Months in the locative case, for "у жовтні" and "Завантаження липня 2026…" (genitive). */
const MONTHS_LOCATIVE = [
    'січні', 'лютому', 'березні', 'квітні', 'травні', 'червні',
    'липні', 'серпні', 'вересні', 'жовтні', 'листопаді', 'грудні',
];

const ROMAN_QUARTERS = ['I', 'II', 'III', 'IV'];

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

/** "14.10" */
export function formatDayMonth(date: string | null | undefined): string {
    if (!date) {
        return '';
    }

    const { month, day } = dateParts(date);

    return `${pad(day)}.${pad(month)}`;
}

/** "7 липня" */
export function formatDayMonthWords(date: string | null | undefined): string {
    if (!date) {
        return '';
    }

    const { month, day } = dateParts(date);

    return `${day} ${MONTHS_GENITIVE[month - 1]}`;
}

/** "Жовтень" */
export function monthName(month: number): string {
    return MONTHS[month - 1] ?? '';
}

/** "жовтня" */
export function monthNameGenitive(month: number): string {
    return MONTHS_GENITIVE[month - 1] ?? '';
}

/** "жовтні" */
export function monthNameLocative(month: number): string {
    return MONTHS_LOCATIVE[month - 1] ?? '';
}

/** "Жовтень 2026" */
export function formatMonthYear(year: number, month: number): string {
    return `${monthName(month)} ${year}`;
}

/** "IV кв." */
export function quarterLabel(quarter: number): string {
    return `${ROMAN_QUARTERS[quarter - 1] ?? quarter} кв.`;
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
