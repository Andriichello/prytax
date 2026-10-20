import { ref, watch, type Ref } from 'vue';
import { useSession } from '@/composables/useSession';

/**
 * A view preference remembered per user on this device (handoff §9.1: Облік's switches and grouping), falling back
 * to the default when storage is unavailable or holds something else.
 */
export function useStoredState<T>(name: string, initial: T, isValid: (value: unknown) => value is T): Ref<T> {
    const { user } = useSession();
    const key = `prytax.${name}.${user.value?.id ?? 'guest'}`;

    let stored: unknown = undefined;

    try {
        const raw = window.localStorage.getItem(key);

        stored = raw === null ? undefined : JSON.parse(raw);
    } catch {
        stored = undefined;
    }

    const state = ref(isValid(stored) ? stored : initial) as Ref<T>;

    watch(state, (value) => {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch {
            // Without storage the preference lasts until the page reloads.
        }
    }, { deep: true });

    return state;
}
