import { computed, ref } from 'vue';
import { api, ApiError, fetchCsrfCookie } from '@/api/client';
import type { ApiResponse, Me, Registration, RegistrationStatus } from '@/api/types';
import { isLocale, type Locale, locale, setLocale } from '@/i18n';

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

    return signedIn(data.user);
}

/**
 * Sign into one person's installation with the password alone (`POST single/login`, builds.md §1.4), while it has one
 * user. A 422 carries the message of `password`.
 */
async function signInWithPassword(password: string, rememberMe = false): Promise<Me> {
    await fetchCsrfCookie();

    const { data } = await api.post<ApiResponse<{ user: Me }>>('single/login', { password, remember: rememberMe });

    return signedIn(data.user);
}

/**
 * Set a new password of one person's installation with its recovery code or the installation's key, and sign in
 * (`POST single/password`). A 422 carries the messages of `secret` and `password`.
 */
async function resetWithRecovery(secret: string, password: string, confirmation: string): Promise<Me> {
    await fetchCsrfCookie();

    const { data } = await api.post<ApiResponse<{ user: Me }>>('single/password', {
        secret,
        password,
        password_confirmation: confirmation,
    });

    return signedIn(data.user);
}

/**
 * Keep the user, who signed in, and the language they chose on the sign-in screen, as their own.
 */
async function signedIn(signed: Me): Promise<Me> {
    remember(signed);
    loading = Promise.resolve(signed);

    const chosen = chosenLocale;
    chosenLocale = null;

    if (chosen && chosen !== signed.locale) {
        await switchLocale(chosen).catch(() => setLocale(chosen));
    }

    return user.value ?? signed;
}

/**
 * Whether a new organization may be registered here, and whether it'd be the installation's first (`GET
 * registration`): the desktop app's first launch opens the form at once.
 */
async function registrationStatus(): Promise<RegistrationStatus> {
    const { data } = await api.get<ApiResponse<RegistrationStatus>>('registration');

    return data;
}

/**
 * Register a new organization (`POST registration`), whose owner is signed in to the session at once, remembered, in
 * the language of the screens. A 422 carries the messages of the fields.
 */
async function register(registration: Registration): Promise<Me> {
    await fetchCsrfCookie();

    const { data } = await api.post<ApiResponse<{ user: Me }>>('registration', {
        ...registration,
        locale: locale.value,
        remember: true,
    });

    chosenLocale = null;
    loading = Promise.resolve(data.user);

    return remember(data.user);
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
        signInWithPassword,
        resetWithRecovery,
        registrationStatus,
        register,
        signOut,
        forget,
        can,
        switchLocale,
    };
}
