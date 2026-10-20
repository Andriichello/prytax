import type { ContractRate } from '@/api/types';

/**
 * The contract's hourly rate on a date for a work type, like the API's Contract::rateFor(): the latest rate of the
 * type in effect on the date, else the latest general one.
 */
export function rateOn(rates: ContractRate[], date: string, chargeTypeId: number | null): ContractRate | null {
    const effective = rates
        .filter((rate) => rate.effective_from <= date)
        .sort((a, b) => b.effective_from.localeCompare(a.effective_from));

    return (chargeTypeId === null ? undefined : effective.find((rate) => rate.charge_type_id === chargeTypeId))
        ?? effective.find((rate) => rate.charge_type_id === null)
        ?? null;
}

const MONTHS_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const DAYS_EN = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

/**
 * A date in PHP's date() format, the letters the templates use: d j m n Y y F M D l (others are kept).
 */
function phpDate(date: string, format: string): string {
    const [year = 0, month = 1, day = 1] = date.slice(0, 10).split('-').map(Number);
    const weekday = new Date(Date.UTC(year, month - 1, day)).getUTCDay();
    const parts: Record<string, string> = {
        d: String(day).padStart(2, '0'),
        j: String(day),
        m: String(month).padStart(2, '0'),
        n: String(month),
        Y: String(year),
        y: String(year).slice(-2),
        F: MONTHS_EN[month - 1] ?? '',
        M: (MONTHS_EN[month - 1] ?? '').slice(0, 3),
        D: (DAYS_EN[weekday] ?? '').slice(0, 3),
        l: DAYS_EN[weekday] ?? '',
    };

    return [...format].map((char) => parts[char] ?? char).join('');
}

/**
 * A contract's description for invoice items, like the API's Contract::describe(): `{name}`, `{number}` and dates
 * such as `{started_at:F j, Y}` filled in.
 */
export function describeContract(template: string, contract: Record<string, string | number | null | undefined>): string {
    const text = template.replace(/\{(\w+)(?::([^}]+))?}/g, (_match, field: string, format: string | undefined) => {
        const value = contract[field];

        if (value === null || value === undefined) {
            return '';
        }

        return /^\d{4}-\d{2}-\d{2}/.test(String(value)) && field.endsWith('_at') ? phpDate(String(value), format ?? 'Y-m-d') : String(value);
    });

    return text.replace(/\s{2,}/g, ' ').trim();
}
