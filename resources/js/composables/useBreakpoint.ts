import { computed, readonly, ref, type ComputedRef } from 'vue';

/**
 * The layout of the screens (design.md §17.1): the desktop one from 600 px, with the sidebar from 1024 px (Tailwind's
 * `lg`) and, below it, the sidebar folded into a rail of icons, which opens it over the page (`isCompact`); the mobile
 * one below 600 px. One media query for each, which every component reads.
 */
const DESKTOP = '(min-width: 600px)';
const SIDEBAR = '(min-width: 1024px)';

const isDesktop = ref(false);
const hasSidebar = ref(false);

if (typeof window !== 'undefined' && typeof window.matchMedia === 'function') {
    for (const [query, flag] of [[DESKTOP, isDesktop], [SIDEBAR, hasSidebar]] as const) {
        const media = window.matchMedia(query);

        flag.value = media.matches;
        media.addEventListener('change', (event) => {
            flag.value = event.matches;
        });
    }
}

const isCompact = computed(() => isDesktop.value && !hasSidebar.value);

export function useBreakpoint(): {
    isDesktop: Readonly<typeof isDesktop>;
    isCompact: ComputedRef<boolean>;
} {
    return { isDesktop: readonly(isDesktop), isCompact };
}
