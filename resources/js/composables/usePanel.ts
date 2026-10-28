import { inject } from 'vue';

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
