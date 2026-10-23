import { computed, ref } from 'vue';
import { api, ApiError, fetchCsrfCookie } from '@/api/client';
import type { ApiResponse, Me } from '@/api/types';
import { isLocale, type Locale, setLocale } from '@/i18n';

/**
 * The signed-in user of the SPA's session cookie, shared by the whole app.
 */
const user = ref<Me | null>(null);

/**
 * The language chosen before signing in, which signing in keeps as the user's own.
 */
let chosenLocale: Locale | null = null;

/**
 * Remember the signed-in user, whose language the screens take.
 */
function remember(me: Me): Me {
    user.value = me;

    if (isLocale(me.locale)) {
        setLocale(me.locale);
    }

    return me;
}

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
        .then(({ data }) => remember(data))
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
 * cookie, until signing out. A 422 carries the messages of the fields. A language chosen on the sign-in screen becomes
 * the user's own; otherwise the screens take the user's.
 */
async function signIn(email: string, password: string, rememberMe = false): Promise<Me> {
    await fetchCsrfCookie();

    const { data } = await api.post<ApiResponse<{ user: Me }>>('auth/login', { email, password, remember: rememberMe });

    remember(data.user);
    loading = Promise.resolve(data.user);

    const chosen = chosenLocale;
    chosenLocale = null;

    if (chosen && chosen !== data.user.locale) {
        await switchLocale(chosen).catch(() => setLocale(chosen));
    }

    return user.value ?? data.user;
}

/**
 * Switch the screens to the language and, when signed in, keep it as the user's own (`PATCH auth/me`), so the API,
 * the e-mails and the user's other devices follow it. Before signing in, it is kept for the account that signs in.
 */
async function switchLocale(value: Locale): Promise<void> {
    setLocale(value);

    if (!user.value) {
        chosenLocale = value;

        return;
    }

    if (user.value.locale !== value) {
        const { data } = await api.patch<ApiResponse<Me>>('auth/me', { locale: value });

        remember(data);
    }
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
        switchLocale,
    };
}
