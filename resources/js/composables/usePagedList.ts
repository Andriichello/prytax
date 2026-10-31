import { onActivated, onDeactivated, onScopeDispose, ref, watch, type Ref, type WatchSource } from 'vue';
import { dataVersion } from '@/api/client';
import type { Paginated } from '@/api/types';
import { useUnderPanel } from '@/composables/usePanel';

export interface PagedList<T> {
    items: Ref<T[]>;
    total: Ref<number | null>;
    hasMore: Ref<boolean>;
    loading: Ref<boolean>;
    loadingMore: Ref<boolean>;
    error: Ref<unknown>;
    /** Why the last next page failed: the rows stay, and the next `loadMore()` tries again. */
    moreError: Ref<unknown>;
    reload: () => Promise<void>;
    loadMore: () => Promise<void>;
    /** Read the pages shown again, keeping the rows on screen until they arrive. */
    refresh: () => Promise<void>;
}

/**
 * A list read page by page (api-core's `page[number]`), for infinite scroll: the first page loads, and again
 * whenever a source changes (the profile, the filters); `loadMore()` appends the next one, leaving out rows already
 * shown (a row added meanwhile shifts the pages). Only the latest request counts: a reload overtaken by another one
 * neither shows its rows nor ends the loading state. A page kept alive (KeepAlive) is shown again as it was left; when
 * the API accepted a write meanwhile (`dataVersion`), it reads its shown pages again, so a record changed meanwhile
 * is up to date while the list keeps its length and scroll. Under the desktop's record panel it does so as soon as a
 * write happens there, and when the panel closes after one.
 */
export function usePagedList<T>(
    fetchPage: (page: number, signal: AbortSignal) => Promise<Paginated<T>>,
    sources: WatchSource[] = [],
): PagedList<T> {
    const items = ref([]) as Ref<T[]>;
    const total = ref<number | null>(null);
    const hasMore = ref(false);
    const loading = ref(true);
    const loadingMore = ref(false);
    const error = ref<unknown>(null);
    const moreError = ref<unknown>(null);

    let page = 0;
    let controller: AbortController | null = null;
    let loadedVersion = dataVersion.value;

    const idOf = (item: T): unknown => (item as { id?: unknown }).id;

    /**
     * Load a page; true when this request is still the latest one, once it is settled.
     */
    async function load(next: number): Promise<boolean> {
        controller?.abort();

        const own = (controller = new AbortController());

        try {
            const result = await fetchPage(next, own.signal);

            if (own !== controller) {
                return false;
            }

            if (next === 1) {
                items.value = result.data;
            } else {
                const shown = new Set(items.value.map(idOf));

                items.value = [...items.value, ...result.data.filter((item) => idOf(item) === undefined || !shown.has(idOf(item)))];
            }

            total.value = result.meta.total;
            hasMore.value = result.meta.links.next !== null;
            page = next;
        } catch (caught) {
            if (own !== controller) {
                return false;
            }

            if (!(caught instanceof DOMException && caught.name === 'AbortError')) {
                if (next === 1) {
                    error.value = caught;
                } else {
                    moreError.value = caught;
                }
            }
        }

        return true;
    }

    async function reload(): Promise<void> {
        loadedVersion = dataVersion.value;
        loading.value = true;
        error.value = null;
        moreError.value = null;

        if (await load(1)) {
            loading.value = false;
        }
    }

    async function loadMore(): Promise<void> {
        if (!hasMore.value || loadingMore.value || loading.value) {
            return;
        }

        loadingMore.value = true;
        moreError.value = null;
        await load(page + 1);
        loadingMore.value = false;
    }

    async function refresh(): Promise<void> {
        if (loading.value || page === 0) {
            return;
        }

        controller?.abort();

        const own = (controller = new AbortController());
        const version = dataVersion.value;

        try {
            const pages = await Promise.all(Array.from({ length: page }, (_, index) => fetchPage(index + 1, own.signal)));

            if (own !== controller) {
                return;
            }

            const shown = new Set<unknown>();

            items.value = pages.flatMap((result) => result.data).filter((item) => {
                const id = idOf(item);

                if (id !== undefined && shown.has(id)) {
                    return false;
                }

                shown.add(id);

                return true;
            });
            total.value = pages.at(-1)!.meta.total;
            hasMore.value = pages.at(-1)!.meta.links.next !== null;
            loadedVersion = version;
        } catch {
            // The rows shown stay; the next visit tries again
        }
    }

    watch(sources, reload, { immediate: true, deep: true });
    onScopeDispose(() => controller?.abort());

    // The first activation is the mount, which loads already
    let activations = 0;
    let active = true;

    onActivated(() => {
        active = true;

        if (activations++ > 0 && dataVersion.value !== loadedVersion) {
            void refresh();
        }
    });
    onDeactivated(() => (active = false));

    // Under the desktop's record panel the list stays shown: a write there reads its pages again, as does closing it
    const underPanel = useUnderPanel();

    watch([dataVersion, underPanel], ([, now], [, before]) => {
        if (active && (now || before) && dataVersion.value !== loadedVersion) {
            void refresh();
        }
    });

    return { items, total, hasMore, loading, loadingMore, error, moreError, reload, loadMore, refresh };
}
