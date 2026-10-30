import { describe, expect, it } from 'vitest';
import { anchoredScroll, clampZoom, stepZoom } from '@/utils/zoom';

describe('stepZoom', () => {
    it('steps to the next level either way', () => {
        expect(stepZoom(1, 1)).toBe(1.25);
        expect(stepZoom(1, -1)).toBe(0.75);
        expect(stepZoom(2, 1)).toBe(3);
    });

    it('steps from between two levels to the nearer one that way', () => {
        expect(stepZoom(1.37, 1)).toBe(1.5);
        expect(stepZoom(1.37, -1)).toBe(1.25);
    });

    it('stays at the ends of the range', () => {
        expect(stepZoom(3, 1)).toBe(3);
        expect(stepZoom(0.5, -1)).toBe(0.5);
    });
});

describe('clampZoom', () => {
    it('keeps the zoom between 50 % and 300 %', () => {
        expect(clampZoom(0.2)).toBe(0.5);
        expect(clampZoom(1.8)).toBe(1.8);
        expect(clampZoom(7)).toBe(3);
    });
});

describe('anchoredScroll', () => {
    it('keeps the anchored point in place as the content grows or shrinks', () => {
        // The point 100 px into a viewport scrolled by 300 px is at 400; doubled, at 800, so the scroll is 700
        expect(anchoredScroll(300, 100, 2)).toBe(700);
        expect(anchoredScroll(700, 100, 0.5)).toBe(300);
    });

    it('never scrolls before the start', () => {
        expect(anchoredScroll(0, 200, 0.5)).toBe(0);
    });
});
