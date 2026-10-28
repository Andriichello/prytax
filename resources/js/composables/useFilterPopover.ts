import { ref, type Ref } from 'vue';
import { useBreakpoint } from '@/composables/useBreakpoint';

/**
 * Which part of a filter a chip opens (design.md §17.4): on desktop each chip opens its own part in a popover under
 * it (`part`, `anchor`), one at a time; on mobile any chip opens the whole drawer.
 */
export function useFilterPopover<Part extends string>(open: Ref<boolean>): {
    part: Ref<Part | undefined>;
    anchor: Ref<HTMLElement | null>;
    openAt: (event: Event, part: Part) => void;
} {
    const { isDesktop } = useBreakpoint();
    const part = ref<Part>();
    const anchor = ref<HTMLElement | null>(null);

    function openAt(event: Event, chosen: Part): void {
        if (isDesktop.value) {
            const chip = event.currentTarget as HTMLElement | null;

            if (open.value && anchor.value === chip) {
                open.value = false;

                return;
            }

            part.value = chosen;
            anchor.value = chip;
        } else {
            part.value = undefined;
            anchor.value = null;
        }

        open.value = true;
    }

    return { part: part as Ref<Part | undefined>, anchor, openAt };
}
