import { nextTick, onBeforeUnmount, type Ref, watch } from 'vue';

/** The open overlays (sheets and full screens), the topmost last: one page lock for all, and Escape closes only the topmost. */
const openOverlays: symbol[] = [];

/**
 * Lock the page behind the overlays (no scrolling, no focus or taps reaching it) while any overlay is open.
 */
function lockPage(): void {
    const locked = openOverlays.length > 0;

    document.body.style.overflow = locked ? 'hidden' : '';
    document.getElementById('app')?.toggleAttribute('inert', locked);
}

/**
 * An overlay over the page (a bottom sheet, or a screen over the whole page): while it is open the page behind is
 * locked and inert, Escape closes it when it is the topmost one, and the focus moves into it and back when it closes.
 */
export function useOverlay(open: Ref<boolean>, close: () => void, element: Ref<HTMLElement | null>): void {
    const token = Symbol('overlay');
    let returnFocus: HTMLElement | null = null;

    function onKeydown(event: KeyboardEvent): void {
        if (event.key === 'Escape' && openOverlays[openOverlays.length - 1] === token) {
            close();
        }
    }

    function release(): void {
        const index = openOverlays.indexOf(token);

        if (index !== -1) {
            openOverlays.splice(index, 1);
            lockPage();
        }

        document.removeEventListener('keydown', onKeydown);
    }

    watch(open, async (isOpen) => {
        if (isOpen) {
            returnFocus = document.activeElement as HTMLElement | null;
            openOverlays.push(token);
            lockPage();
            document.addEventListener('keydown', onKeydown);
            await nextTick();
            element.value?.focus();
        } else if (openOverlays.includes(token)) {
            release();
            returnFocus?.focus();
        }
    }, { immediate: true });

    onBeforeUnmount(release);
}
