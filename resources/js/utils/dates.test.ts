import { describe, expect, it } from 'vitest';
import {
    calendarWeeks,
    daysBetween,
    daysInMonth,
    formatDate,
    formatPeriod,
    parseDisplayDate,
    quarterLabel,
    quarterOf,
    weekdayNames,
} from '@/utils/dates';

describe('parseDisplayDate', () => {
    it('reads dates typed the Ukrainian way', () => {
        expect(parseDisplayDate('14.10.2026')).toBe('2026-10-14');
        expect(parseDisplayDate(' 4.1.2026 ')).toBe('2026-01-04');
        expect(parseDisplayDate('14/10/2026')).toBe('2026-10-14');
        expect(parseDisplayDate('14-10-2026')).toBe('2026-10-14');
        expect(parseDisplayDate('14102026')).toBe('2026-10-14');
    });

    it('refuses incomplete and impossible dates', () => {
        expect(parseDisplayDate('14.10')).toBeNull();
        expect(parseDisplayDate('31.02.2026')).toBeNull();
        expect(parseDisplayDate('29.02.2026')).toBeNull();
        expect(parseDisplayDate('13.13.2026')).toBeNull();
        expect(parseDisplayDate('2026-10-14')).toBeNull();
    });

    it('takes the leap day of a leap year', () => {
        expect(parseDisplayDate('29.02.2028')).toBe('2028-02-29');
    });

    it('reads back what formatDate writes', () => {
        expect(parseDisplayDate(formatDate('2026-03-31'))).toBe('2026-03-31');
    });
});

describe('calendar helpers', () => {
    it('splits dates as text, so no time zone moves them', () => {
        expect(formatDate('2026-10-05T23:30:00.000000Z')).toBe('05.10.2026');
        expect(formatDate(null)).toBe('');
    });

    it('names quarters with Roman numerals', () => {
        expect(quarterOf(10)).toBe(4);
        expect(quarterOf('2026-03-31')).toBe(1);
        expect(quarterLabel(3)).toBe('III кв.');
    });

    it('counts whole days across a change of the clock', () => {
        expect(daysBetween('2026-03-28', '2026-03-30')).toBe(2);
        expect(daysBetween('2026-10-20', '2026-10-19')).toBe(-1);
    });

    it('writes the year of a period once, unless it spans two', () => {
        expect(formatPeriod('2026-10-01', '2026-10-14')).toBe('01.10 – 14.10.2026');
        expect(formatPeriod('2025-12-16', '2026-01-15')).toBe('16.12.2025 – 15.01.2026');
    });
});

describe('calendarWeeks', () => {
    it('starts the weeks on Monday, with blanks around the month', () => {
        const weeks = calendarWeeks(2026, 10);

        expect(weeks).toHaveLength(5);
        expect(weeks[0]).toEqual([null, null, null, '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04']);
        expect(weeks[4]).toEqual(['2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', null]);
    });

    it('fills exact weeks without blanks, and a month starting on Sunday spans six', () => {
        expect(calendarWeeks(2027, 2)).toHaveLength(4);
        expect(calendarWeeks(2027, 2)[0]![0]).toBe('2027-02-01');
        expect(calendarWeeks(2026, 3)).toHaveLength(6);
        expect(calendarWeeks(2026, 3)[0]![6]).toBe('2026-03-01');
    });

    it('counts the leap day', () => {
        expect(daysInMonth(2028, 2)).toBe(29);
        expect(daysInMonth(2026, 2)).toBe(28);
        expect(calendarWeeks(2028, 2).flat()).toContain('2028-02-29');
    });

    it('names the weekdays, Monday first', () => {
        expect(weekdayNames()).toEqual(['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Нд']);
    });
});
