import { describe, expect, it } from 'vitest';
import { addDays, addMonths, presetRange, rangeDays, tapRange } from '@/utils/datePicker';

describe('tapRange', () => {
    it('sets the start, then the end', () => {
        const started = tapRange({ start: '', end: '' }, '2026-11-01');

        expect(started).toEqual({ start: '2026-11-01', end: '' });
        expect(tapRange(started, '2026-11-30')).toEqual({ start: '2026-11-01', end: '2026-11-30' });
    });

    it('ends a period on its start day', () => {
        expect(tapRange({ start: '2026-11-05', end: '' }, '2026-11-05')).toEqual({ start: '2026-11-05', end: '2026-11-05' });
    });

    it('starts again from a day before the start', () => {
        expect(tapRange({ start: '2026-11-10', end: '' }, '2026-11-03')).toEqual({ start: '2026-11-03', end: '' });
    });

    it('starts a new period on the third tap', () => {
        expect(tapRange({ start: '2026-11-01', end: '2026-11-30' }, '2026-11-20')).toEqual({ start: '2026-11-20', end: '' });
    });
});

describe('presetRange', () => {
    it('picks the months and the quarter around today', () => {
        expect(presetRange('thisMonth', '2026-10-20')).toEqual({ start: '2026-10-01', end: '2026-10-31' });
        expect(presetRange('nextMonth', '2026-10-20')).toEqual({ start: '2026-11-01', end: '2026-11-30' });
        expect(presetRange('lastMonth', '2026-10-20')).toEqual({ start: '2026-09-01', end: '2026-09-30' });
        expect(presetRange('thisQuarter', '2026-10-20')).toEqual({ start: '2026-10-01', end: '2026-12-31' });
    });

    it('crosses the year', () => {
        expect(presetRange('nextMonth', '2026-12-05')).toEqual({ start: '2027-01-01', end: '2027-01-31' });
        expect(presetRange('lastMonth', '2026-01-05')).toEqual({ start: '2025-12-01', end: '2025-12-31' });
        expect(presetRange('nextMonth', '2028-01-31')).toEqual({ start: '2028-02-01', end: '2028-02-29' });
    });
});

describe('moving and counting days', () => {
    it('moves by days across months and years', () => {
        expect(addDays('2026-10-31', 1)).toBe('2026-11-01');
        expect(addDays('2026-01-01', -1)).toBe('2025-12-31');
        expect(addDays('2026-10-20', -7)).toBe('2026-10-13');
    });

    it('moves by months', () => {
        expect(addMonths(2026, 12, 1)).toEqual({ year: 2027, month: 1 });
        expect(addMonths(2026, 1, -1)).toEqual({ year: 2025, month: 12 });
    });

    it('counts both ends of a period', () => {
        expect(rangeDays('2026-11-01', '2026-11-30')).toBe(30);
        expect(rangeDays('2026-11-05', '2026-11-05')).toBe(1);
    });
});
