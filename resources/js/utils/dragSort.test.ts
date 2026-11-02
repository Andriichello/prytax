import { describe, expect, it } from 'vitest';
import { dragTarget, moveItem } from '@/utils/dragSort';

/** Three rows 40 px high, one under another from 100 px. */
const rows = [{ top: 100, height: 40 }, { top: 140, height: 40 }, { top: 180, height: 40 }];

describe('dragTarget', () => {
    it('takes the place of the row before or after, once past its middle, and keeps its own until then', () => {
        expect(dragTarget(1, 119, 159, rows)).toBe(0);
        expect(dragTarget(1, 121, 161, rows)).toBe(1);
        expect(dragTarget(1, 161, 201, rows)).toBe(2);
        expect(dragTarget(1, 159, 199, rows)).toBe(1);
    });

    it('goes nowhere past the ends', () => {
        expect(dragTarget(0, 10, 50, rows)).toBe(0);
        expect(dragTarget(2, 260, 300, rows)).toBe(2);
    });
});

describe('moveItem', () => {
    it('moves an item up or down in place, and ignores a place outside the list', () => {
        const list = ['holder', 'bank', 'iban'];

        moveItem(list, 2, 0);
        expect(list).toEqual(['iban', 'holder', 'bank']);

        moveItem(list, 0, 1);
        expect(list).toEqual(['holder', 'iban', 'bank']);

        moveItem(list, 2, 3);
        expect(list).toEqual(['holder', 'iban', 'bank']);
    });
});
