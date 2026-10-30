/**
 * The zoom of a document's preview, as a share of the width it fits at (1): the levels − and + step through, and the
 * range a pinch or Ctrl+wheel moves within.
 */
export const ZOOM_LEVELS = [0.5, 0.75, 1, 1.25, 1.5, 2, 3] as const;
export const MIN_ZOOM = ZOOM_LEVELS[0];
export const MAX_ZOOM = ZOOM_LEVELS[ZOOM_LEVELS.length - 1];

/** Keep the zoom within the range. */
export function clampZoom(zoom: number): number {
    return Math.min(Math.max(zoom, MIN_ZOOM), MAX_ZOOM);
}

/**
 * The next level above (1) or below (-1) the zoom, which a pinch may have left between two levels; the range's end
 * stays where it is.
 */
export function stepZoom(zoom: number, direction: 1 | -1): number {
    const next = direction === 1
        ? ZOOM_LEVELS.find((level) => level > zoom + 0.001)
        : [...ZOOM_LEVELS].reverse().find((level) => level < zoom - 0.001);

    return next ?? (direction === 1 ? MAX_ZOOM : MIN_ZOOM);
}

/**
 * Where to scroll to after the content grew by the ratio, so the point at `anchor` (from the viewport's start) stays
 * under it.
 */
export function anchoredScroll(scroll: number, anchor: number, ratio: number): number {
    return Math.max((scroll + anchor) * ratio - anchor, 0);
}
