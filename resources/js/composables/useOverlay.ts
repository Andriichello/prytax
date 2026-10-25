import { nextTick, onBeforeUnmount, ref, type Ref, watch } from 'vue';

/** An open overlay (a sheet or a full screen) and its element. */
interface OpenOverlay {
    token: symbol;
    element: Ref<HTMLElement | null>;
}

/** The open overlays, the topmost last: one page lock for all, and Escape closes only the topmost. */
const openOverlays: OpenOverlay[] = [];

/**
 * Lock the page behind the overlays (no scrolling, no focus or taps reaching it) while any overlay is open, and every
 * overlay under the topmost one, which alone takes taps and focus.
 */
function lockPage(): void {
    const locked = openOverlays.length > 0;

    document.body.style.overflow = locked ? 'hidden' : '';
    document.getElementById('app')?.toggleAttribute('inert', locked);
    openOverlays.forEach((overlay, index) => overlay.element.value?.toggleAttribute('inert', index < openOverlays.length - 1));
}

/**
 * An overlay over the page (a bottom sheet, or a screen over the whole page): while it is open the page behind is
 * locked and inert, Escape closes it when it is the topmost one, and the focus moves into it and back when it closes.
 * Its `layer` (0 over the page, 1 over another overlay, …) lifts it and its backdrop over the overlays opened before,
 * which stay inert under it until it closes.
 */
export function useOverlay(open: Ref<boolean>, close: () => void, element: Ref<HTMLElement | null>): { layer: Ref<number> } {
    const token = Symbol('overlay');
    const layer = ref(0);
    let returnFocus: HTMLElement | null = null;

    const isOpen = (): boolean => openOverlays.some((overlay) => overlay.token === token);

    function onKeydown(event: KeyboardEvent): void {
        if (event.key === 'Escape' && openOverlays[openOverlays.length - 1]?.token === token) {
            close();
        }
    }

    function release(): void {
        const index = openOverlays.findIndex((overlay) => overlay.token === token);

        if (index !== -1) {
            openOverlays.splice(index, 1);
            lockPage();
        }

        element.value?.removeAttribute('inert');
        document.removeEventListener('keydown', onKeydown);
    }

    watch(open, async (opening) => {
        if (opening) {
            returnFocus = document.activeElement as HTMLElement | null;
            layer.value = openOverlays.length;
            openOverlays.push({ token, element });
            lockPage();
            document.addEventListener('keydown', onKeydown);
            await nextTick();
            element.value?.focus();
        } else if (isOpen()) {
            release();
            returnFocus?.focus();
        }
    }, { immediate: true });

    onBeforeUnmount(release);

    return { layer };
}
