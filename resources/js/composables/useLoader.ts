import { onScopeDispose, ref, watch, type Ref, type WatchSource } from 'vue';

export interface Loader<T> {
    data: Ref<T | null>;
    error: Ref<unknown>;
    loading: Ref<boolean>;
    reload: () => Promise<void>;
}

/**
 * Load data for a screen, again whenever a source changes (the profile, a route param); a request, which a newer one
 * replaced, is aborted and its answer ignored.
 */
export function useLoader<T>(fetcher: (signal: AbortSignal) => Promise<T>, sources: WatchSource[] = []): Loader<T> {
    const data = ref(null) as Ref<T | null>;
    const error = ref<unknown>(null);
    const loading = ref(true);

    let controller: AbortController | null = null;

    async function reload(): Promise<void> {
        controller?.abort();

        const own = (controller = new AbortController());

        loading.value = true;
        error.value = null;

        try {
            const result = await fetcher(own.signal);

            if (own === controller) {
                data.value = result;
            }
        } catch (caught) {
            if (own === controller && !(caught instanceof DOMException && caught.name === 'AbortError')) {
                error.value = caught;
            }
        } finally {
            if (own === controller) {
                loading.value = false;
            }
        }
    }

    watch(sources, reload, { immediate: true });
    onScopeDispose(() => controller?.abort());

    return { data, error, loading, reload };
}
