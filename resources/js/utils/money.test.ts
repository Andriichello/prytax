import { afterEach, describe, expect, it } from 'vitest';
import {
    GROUP_SPACE,
    MINUS,
    compareAmounts,
    formatAmount,
    formatMoney,
    multiplyAmounts,
    multiplyDecimals,
    parseAmount,
    percentOf,
    setMoneyFormat,
    subtractAmounts,
    sumAmounts,
} from '@/utils/money';

/** The no-break space between an amount and its currency. */
const NBSP = String.fromCharCode(0xa0);

afterEach(() => setMoneyFormat('space_dot'));

describe('formatAmount', () => {
    it('groups thousands with a narrow no-break space by default', () => {
        expect(formatAmount('1840135.05')).toBe(`1${GROUP_SPACE}840${GROUP_SPACE}135.05`);
    });

    it('follows the profile format', () => {
        setMoneyFormat('dot_comma');

        expect(formatAmount('2851.99')).toBe('2.851,99');
        expect(formatAmount('2851.99', { format: 'plain_dot' })).toBe('2851.99');
    });

    it('keeps the decimals of rates as they are', () => {
        expect(formatAmount('41.8710')).toBe('41.8710');
    });

    it('signs with the minus sign, never a hyphen, and drops the sign of zero', () => {
        expect(formatAmount('-18413.69')).toBe(`${MINUS}18${GROUP_SPACE}413.69`);
        expect(formatAmount('-0.00')).toBe('0.00');
        expect(formatAmount('125.00', { signed: true })).toBe('+125.00');
    });

    it('puts the currency after the amount, hryvnias as ₴', () => {
        expect(formatMoney('3892.50', 'usd')).toBe(`3${GROUP_SPACE}892.50${NBSP}USD`);
        expect(formatMoney('286410.37', 'UAH')).toBe(`286${GROUP_SPACE}410.37${NBSP}₴`);
    });
});

describe('parseAmount', () => {
    it('takes the last separator as the decimal one', () => {
        expect(parseAmount('2 851,99')).toBe('2851.99');
        expect(parseAmount('2,851.99')).toBe('2851.99');
        expect(parseAmount('2.851,99')).toBe('2851.99');
        expect(parseAmount(`1${GROUP_SPACE}234`)).toBe('1234');
    });

    it('turns the minus sign into a hyphen', () => {
        expect(parseAmount(`${MINUS}12.5`)).toBe('-12.5');
    });

    it('refuses what isn\'t a number', () => {
        expect(parseAmount('')).toBeNull();
        expect(parseAmount('12a')).toBeNull();
        expect(parseAmount('1.')).toBeNull();
    });
});

describe('exact arithmetic', () => {
    it('sums and subtracts by cents', () => {
        expect(sumAmounts('0.10', '0.20', null, '1.005')).toBe('1.30');
        expect(subtractAmounts('0.10', '0.30')).toBe('-0.20');
        expect(subtractAmounts('3892.50', '3892.50')).toBe('0.00');
    });

    it('compares by cents', () => {
        expect(compareAmounts('25155.93', '25155.94')).toBe(-1);
        expect(compareAmounts('-0.5', '-0.50')).toBe(0);
        expect(compareAmounts('10', '9.99')).toBe(1);
    });

    it('rounds products half up to two decimals, like the API', () => {
        expect(multiplyAmounts('86.50', '45.00')).toBe('3892.50');
        expect(multiplyAmounts('0.5', '0.01')).toBe('0.01');
        expect(multiplyDecimals('3892.50', '42.2297')).toBe('164379.11');
        expect(multiplyDecimals('-1.005', '1')).toBe('-1.01');
    });
});

describe('percentOf', () => {
    it('never reaches 100 % before the total, nor 0 % after the first cent', () => {
        expect(percentOf('2150', '4300')).toBe(50);
        expect(percentOf('4299.99', '4300')).toBe(99);
        expect(percentOf('0.01', '4300')).toBe(1);
        expect(percentOf('4300', '4300')).toBe(100);
        expect(percentOf('0', '4300')).toBe(0);
    });
});
