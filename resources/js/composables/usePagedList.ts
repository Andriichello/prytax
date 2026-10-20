import { onScopeDispose, ref, watch, type Ref, type WatchSource } from 'vue';
import type { Paginated } from '@/api/types';

export interface PagedList<T> {
    items: Ref<T[]>;
    total: Ref<number | null>;
    hasMore: Ref<boolean>;
    loading: Ref<boolean>;
    loadingMore: Ref<boolean>;
    error: Ref<unknown>;
    reload: () => Promise<void>;
    loadMore: () => Promise<void>;
}

/**
 * A list read page by page (api-core's `page[number]`), for infinite scroll: the first page loads, and again
 * whenever a source changes (the profile, the filters); `loadMore()` appends the next one.
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

    let page = 0;
    let controller: AbortController | null = null;

    async function load(next: number): Promise<void> {
        controller?.abort();

        const own = (controller = new AbortController());

        error.value = null;

        try {
            const result = await fetchPage(next, own.signal);

            if (own !== controller) {
                return;
            }

            items.value = next === 1 ? result.data : [...items.value, ...result.data];
            total.value = result.meta.total;
            hasMore.value = result.meta.links.next !== null;
            page = next;
        } catch (caught) {
            if (own === controller && !(caught instanceof DOMException && caught.name === 'AbortError')) {
                error.value = caught;
            }
        }
    }

    async function reload(): Promise<void> {
        loading.value = true;
        await load(1);
        loading.value = false;
    }

    async function loadMore(): Promise<void> {
        if (!hasMore.value || loadingMore.value || loading.value) {
            return;
        }

        loadingMore.value = true;
        await load(page + 1);
        loadingMore.value = false;
    }

    watch(sources, reload, { immediate: true, deep: true });
    onScopeDispose(() => controller?.abort());

    return { items, total, hasMore, loading, loadingMore, error, reload, loadMore };
}
