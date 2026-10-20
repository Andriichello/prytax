import { computed, ref } from 'vue';
import { api, ApiError, fetchCsrfCookie } from '@/api/client';
import type { ApiResponse, Me } from '@/api/types';

/**
 * The signed-in user of the SPA's session cookie, shared by the whole app.
 */
const user = ref<Me | null>(null);

let loading: Promise<Me | null> | null = null;

/**
 * Load the signed-in user once (`GET auth/me`); a 401 means nobody is signed in.
 */
function load(force = false): Promise<Me | null> {
    if (loading && !force) {
        return loading;
    }

    loading = api
        .get<ApiResponse<Me>>('auth/me')
        .then(({ data }) => (user.value = data))
        .catch((error: unknown) => {
            if (error instanceof ApiError && error.status === 401) {
                return (user.value = null);
            }

            loading = null;
            throw error;
        });

    return loading;
}

/**
 * Sign in to the session: the CSRF cookie first, then the credentials. A remembered login outlives the session in a
 * cookie, until signing out. A 422 carries the messages of the fields.
 */
async function signIn(email: string, password: string, remember = false): Promise<Me> {
    await fetchCsrfCookie();

    const { data } = await api.post<ApiResponse<{ user: Me }>>('auth/login', { email, password, remember });

    user.value = data.user;
    loading = Promise.resolve(data.user);

    return data.user;
}

async function signOut(): Promise<void> {
    try {
        await api.post('auth/logout');
    } finally {
        forget();
    }
}

/**
 * Forget the user locally, when the session has ended on the server (a 401).
 */
function forget(): void {
    user.value = null;
    loading = Promise.resolve(null);
}

/**
 * Whether the user may do an action on a kind of record: can('create', 'invoices'), can('configure', 'profiles').
 */
function can(action: string, resource: string): boolean {
    return user.value?.permissions.includes(`${action} models.${resource}`) ?? false;
}

export function useSession() {
    return {
        user: computed(() => user.value),
        isSignedIn: computed(() => user.value !== null),
        load,
        signIn,
        signOut,
        forget,
        can,
    };
}
