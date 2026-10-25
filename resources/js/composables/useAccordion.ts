import { computed, inject, provide, ref, type ComputedRef, type InjectionKey, type Ref } from 'vue';

/**
 * The accordion of a form (design.md §16.3): the fields are grouped into cards, and one group at a time opens for
 * editing; the rest of the page dims and goes inert until it is saved or cancelled.
 */
export interface Accordion {
    /** The group open for editing, or null. */
    open: Ref<string | null>;
    /** Whether a group is open, so everything else dims. */
    busy: ComputedRef<boolean>;
}

const KEY: InjectionKey<Accordion> = Symbol('accordion');

/**
 * Start the accordion of a page, which its groups and dimmed blocks share.
 */
export function provideAccordion(): Accordion {
    const open = ref<string | null>(null);
    const accordion = { open, busy: computed(() => open.value !== null) };

    provide(KEY, accordion);

    return accordion;
}

/**
 * Get the accordion of the page; a group outside of one opens on its own.
 */
export function useAccordion(): Accordion {
    return inject(KEY, () => {
        const open = ref<string | null>(null);

        return { open, busy: computed(() => open.value !== null) };
    }, true);
}
