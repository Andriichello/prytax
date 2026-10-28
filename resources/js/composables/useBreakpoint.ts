import { readonly, ref } from 'vue';

/**
 * The layout of the screens (design.md §17.1): the desktop one from 1024 px (Tailwind's `lg`), the mobile one below
 * it, and whether the right-hand columns fit beside the main one (from 1280 px). One media query for the whole app,
 * which every component reads.
 */
const DESKTOP = '(min-width: 1024px)';
const WIDE = '(min-width: 1280px)';

const isDesktop = ref(false);
const isWide = ref(false);

if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
    const desktop = window.matchMedia(DESKTOP);
    const wide = window.matchMedia(WIDE);

    isDesktop.value = desktop.matches;
    isWide.value = wide.matches;
    desktop.addEventListener('change', (event) => {
        isDesktop.value = event.matches;
    });
    wide.addEventListener('change', (event) => {
        isWide.value = event.matches;
    });
}

export function useBreakpoint(): { isDesktop: Readonly<typeof isDesktop>; isWide: Readonly<typeof isWide> } {
    return { isDesktop: readonly(isDesktop), isWide: readonly(isWide) };
}
