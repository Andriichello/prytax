import { dateOf, dateParts, daysBetween, daysInMonth, quarterOf } from '@/utils/dates';

/**
 * The rules of the date picker (design.md §16.6): moving by days and months, the taps of a period, and its presets.
 * Dates are `YYYY-MM-DD` strings, as everywhere in the app.
 */

/** A period being picked: its start, and its end once the second tap set it. */
export interface PickedRange {
    start: string;
    end: string;
}

/** The presets of the period picker, in their order on the screen. */
export type RangePreset = 'thisMonth' | 'nextMonth' | 'lastMonth' | 'thisQuarter';

export const RANGE_PRESETS: RangePreset[] = ['thisMonth', 'nextMonth', 'lastMonth', 'thisQuarter'];

/** The day `days` after a date (before it, when negative). */
export function addDays(date: string, days: number): string {
    const { year, month, day } = dateParts(date);
    const moved = new Date(Date.UTC(year, month - 1, day + days));

    return dateOf(moved.getUTCFullYear(), moved.getUTCMonth() + 1, moved.getUTCDate());
}

/** The month `months` after the month of a year (before it, when negative). */
export function addMonths(year: number, month: number, months: number): { year: number; month: number } {
    const index = year * 12 + month - 1 + months;

    return { year: Math.floor(index / 12), month: (index % 12) + 1 };
}

/**
 * The period after a tap on a day (`renderVals()` of the handoff's `M-DateRangePicker`): the first tap sets the start,
 * the second the end; a tap before the start starts again from it, and a tap once both are set starts a new period.
 */
export function tapRange(range: PickedRange, date: string): PickedRange {
    if (!range.start || range.end || date < range.start) {
        return { start: date, end: '' };
    }

    return { start: range.start, end: date };
}

/** The period a preset picks, from today. */
export function presetRange(preset: RangePreset, today: string): PickedRange {
    const { year, month } = dateParts(today);
    const monthRange = (shift: number): PickedRange => {
        const shown = addMonths(year, month, shift);

        return { start: dateOf(shown.year, shown.month, 1), end: dateOf(shown.year, shown.month, daysInMonth(shown.year, shown.month)) };
    };

    switch (preset) {
        case 'nextMonth':
            return monthRange(1);
        case 'lastMonth':
            return monthRange(-1);
        case 'thisQuarter': {
            const first = (quarterOf(month) - 1) * 3 + 1;

            return { start: dateOf(year, first, 1), end: dateOf(year, first + 2, daysInMonth(year, first + 2)) };
        }
        default:
            return monthRange(0);
    }
}

/** The days of a period, both ends included: 1 November – 30 November is 30. */
export function rangeDays(start: string, end: string): number {
    return daysBetween(start, end) + 1;
}
