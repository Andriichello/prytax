import { computed, inject, type ComputedRef, type InjectionKey } from 'vue';

/** The record panel of the desktop layout (design.md §17.3). */
export interface RecordPanelContext {
    close: () => void;
}

/**
 * The record panel a page is shown in (`RecordPanel` provides it), or null on a page: in the panel the record's title,
 * sub-line and badges go to the panel's header (`SubScreen`'s `record`), so the page leaves out its own.
 */
export function usePanel(): RecordPanelContext | null {
    return inject<RecordPanelContext | null>('inPanel', null);
}

/** Whether a record panel is open over the page (`PageRoute` provides it for the page under it). */
export const UNDER_PANEL: InjectionKey<ComputedRef<boolean>> = Symbol('underPanel');

/**
 * Whether a record panel is open over the page this is part of: the page stays shown under it, so what it loaded may
 * change there (a tax paid, an invoice saved). Outside a page under the panel, never.
 */
export function useUnderPanel(): ComputedRef<boolean> {
    return inject(UNDER_PANEL, computed(() => false));
}
