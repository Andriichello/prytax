import { nextTick, type Ref, ref } from 'vue';
import { dragTarget, moveItem } from '@/utils/dragSort';

/** How near the edge of the scrolled area the pointer scrolls it while dragging, and by how much a frame, in px. */
const EDGE = 64;
const STEP = 12;

/**
 * Drag and drop of a list's rows by a handle, with a mouse, a pen or a finger (pointer events; the handle has
 * `touch-action: none`, so a finger drags rather than scrolls). The row follows the pointer and takes the place of
 * the row before or after it once past its middle; near the top or the bottom of the scrolled area, it scrolls. On the
 * handle, ↑ and ↓ move the row too, keeping the focus on it. The rows are `[data-sort-row]` elements of the
 * container, their handles `[data-sort-handle]`.
 *
 * @param list The list, which is reordered in place.
 * @param container The element of the rows.
 */
export function useDragSort<T>(list: () => T[], container: Ref<HTMLElement | null>) {
    /** The index of the row dragged now, or null. */
    const dragging = ref<number | null>(null);
    /** How far the dragged row is from its place, in px. */
    const offset = ref(0);

    let scroller: HTMLElement | null = null;
    let start = 0;
    let pointerY = 0;
    let frame = 0;

    /** The element, which scrolls the list: the nearest scrolled ancestor, else none (the window). */
    function scrollerOf(element: HTMLElement | null): HTMLElement | null {
        for (let node = element?.parentElement ?? null; node; node = node.parentElement) {
            const { overflowY } = getComputedStyle(node);

            if ((overflowY === 'auto' || overflowY === 'scroll') && node.scrollHeight > node.clientHeight) {
                return node;
            }
        }

        return null;
    }

    const scrolled = (): number => (scroller ? scroller.scrollTop : window.scrollY);
    const rows = (): HTMLElement[] => [...(container.value?.querySelectorAll<HTMLElement>(':scope > [data-sort-row]') ?? [])];

    /** Follow the pointer, and give the dragged row the place it passed into. */
    function follow(): void {
        const index = dragging.value;

        if (index === null) {
            return;
        }

        offset.value = pointerY + scrolled() - start;

        const rects = rows().map((row) => row.getBoundingClientRect());
        const dragged = rects[index];

        if (!dragged) {
            return;
        }

        const target = dragTarget(index, dragged.top, dragged.bottom, rects);

        if (target !== index) {
            // Its place moves by the passed row's height, so it stays under the pointer
            start += (target > index ? 1 : -1) * rects[target]!.height;
            moveItem(list(), index, target);
            dragging.value = target;
            offset.value = pointerY + scrolled() - start;
        }
    }

    /** Scroll near the edges while dragging, a step a frame. */
    function scroll(): void {
        const top = scroller ? scroller.getBoundingClientRect().top : 0;
        const bottom = scroller ? scroller.getBoundingClientRect().bottom : window.innerHeight;
        const by = pointerY < top + EDGE ? -STEP : pointerY > bottom - EDGE ? STEP : 0;

        if (by !== 0) {
            if (scroller) {
                scroller.scrollTop += by;
            } else {
                window.scrollBy(0, by);
            }

            follow();
        }

        frame = requestAnimationFrame(scroll);
    }

    /** Start dragging the row from its handle. */
    function begin(index: number, event: PointerEvent): void {
        if (event.pointerType === 'mouse' && event.button !== 0) {
            return;
        }

        event.preventDefault();
        (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
        scroller = scrollerOf(container.value);
        pointerY = event.clientY;
        start = pointerY + scrolled();
        offset.value = 0;
        dragging.value = index;
        frame = requestAnimationFrame(scroll);
    }

    function move(event: PointerEvent): void {
        if (dragging.value !== null) {
            pointerY = event.clientY;
            follow();
        }
    }

    function end(): void {
        cancelAnimationFrame(frame);
        dragging.value = null;
        offset.value = 0;
    }

    /** ↑ and ↓ on a handle move its row, which keeps the focus. */
    async function key(index: number, event: KeyboardEvent): Promise<void> {
        const by = event.key === 'ArrowUp' ? -1 : event.key === 'ArrowDown' ? 1 : 0;

        if (by === 0 || index + by < 0 || index + by >= list().length) {
            return;
        }

        event.preventDefault();
        moveItem(list(), index, index + by);
        await nextTick();
        rows()[index + by]?.querySelector<HTMLElement>('[data-sort-handle]')?.focus();
    }

    return { dragging, offset, begin, move, end, key };
}
