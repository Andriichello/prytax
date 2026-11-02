/**
 * The place a dragged row of a list takes, as its own top or bottom (where it's dragged to now) passes the middle of
 * the row before or after it: that row's index, or its own while it passes neither.
 */
export function dragTarget(index: number, top: number, bottom: number, rows: { top: number; height: number }[]): number {
    const previous = rows[index - 1];

    if (previous && top < previous.top + previous.height / 2) {
        return index - 1;
    }

    const next = rows[index + 1];

    if (next && bottom > next.top + next.height / 2) {
        return index + 1;
    }

    return index;
}

/**
 * Move the item at an index of the list to another index, in place.
 */
export function moveItem<T>(list: T[], from: number, to: number): void {
    if (from === to || to < 0 || to >= list.length) {
        return;
    }

    const [item] = list.splice(from, 1);

    list.splice(to, 0, item!);
}
