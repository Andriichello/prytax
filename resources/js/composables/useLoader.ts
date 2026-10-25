import { onActivated, onScopeDispose, ref, watch, type Ref, type WatchSource } from 'vue';
import { dataVersion } from '@/api/client';

export interface Loader<T> {
    data: Ref<T | null>;
    error: Ref<unknown>;
    loading: Ref<boolean>;
    reload: () => Promise<void>;
}

/**
 * Load data for a screen, again whenever a source changes (the profile, a route param); a request, which a newer one
 * replaced, is aborted and its answer ignored. A page kept alive (KeepAlive) is shown again as it was left; it loads
 * again only when the API accepted a write since its last load (`dataVersion`), keeping the data on screen meanwhile.
 */
export function useLoader<T>(fetcher: (signal: AbortSignal) => Promise<T>, sources: WatchSource[] = []): Loader<T> {
    const data = ref(null) as Ref<T | null>;
    const error = ref<unknown>(null);
    const loading = ref(true);

    let controller: AbortController | null = null;
    let loadedVersion = dataVersion.value;

    async function reload(): Promise<void> {
        controller?.abort();
        loadedVersion = dataVersion.value;

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

    // The first activation is the mount, which loads already
    let activations = 0;

    onActivated(() => {
        if (activations++ > 0 && dataVersion.value !== loadedVersion) {
            void reload();
        }
    });

    return { data, error, loading, reload };
}
