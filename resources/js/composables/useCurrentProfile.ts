import { computed, ref } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, Me, Profile, ProfileSettings } from '@/api/types';
import { setMoneyFormat } from '@/utils/money';

/**
 * The ФОП profiles of the organization and the one the app works in. The API links to profile records without the
 * profile in the path (`/invoices/12`), so the current profile is app state, remembered per user on the device.
 */
const profiles = ref<Profile[]>([]);
const currentId = ref<number | null>(null);
const settings = ref<ProfileSettings | null>(null);

let storageKey: string | null = null;

function remembered(): number | null {
    try {
        const value = storageKey ? window.localStorage.getItem(storageKey) : null;

        return value ? Number(value) : null;
    } catch {
        return null;
    }
}

function remember(id: number): void {
    try {
        if (storageKey) {
            window.localStorage.setItem(storageKey, String(id));
        }
    } catch {
        // Storage may be unavailable (private mode); the choice then lasts until the page reloads.
    }
}

/**
 * Load the profiles with their unread notification counts and correction flags (again after writes and on the screens
 * that show them, as every write may raise or resolve notifications).
 */
async function loadProfiles(): Promise<Profile[]> {
    const data = await api.all<Profile>('profiles', { append: ['unread_alerts_count', 'needs_correction'] });

    profiles.value = data;

    return data;
}

/**
 * Load the settings of the current profile; an answer for a profile, which is no longer current, is dropped.
 */
async function loadSettings(): Promise<void> {
    const id = currentId.value;

    if (id === null) {
        return;
    }

    const { data } = await api.get<ApiResponse<ProfileSettings>>(`profiles/${id}/settings`);

    if (currentId.value !== id) {
        return;
    }

    settings.value = data;
    setMoneyFormat(data.display.money_format);
}

/**
 * Switch the app to a profile and load its settings.
 */
async function select(id: number): Promise<void> {
    if (currentId.value === id && settings.value) {
        return;
    }

    currentId.value = id;
    settings.value = null;
    remember(id);

    await loadSettings();
}

/**
 * Choose the profile to work in after signing in: the one this user worked in last on this device, else the user's
 * own (by e-mail), else the first active one.
 */
async function ensure(user: Me): Promise<Profile | null> {
    storageKey = `prytax.profile.${user.id}`;

    if (profiles.value.length === 0) {
        await loadProfiles();
    }

    const last = remembered();
    const profile = profiles.value.find((item) => item.id === (currentId.value ?? last))
        ?? profiles.value.find((item) => item.is_active && item.email?.toLowerCase() === user.email.toLowerCase())
        ?? profiles.value.find((item) => item.is_active)
        ?? profiles.value[0]
        ?? null;

    if (profile) {
        await select(profile.id);
    }

    return profile;
}

function reset(): void {
    profiles.value = [];
    currentId.value = null;
    settings.value = null;
    storageKey = null;
}

/**
 * Lower the unread count of the current profile after a notification is read, without reloading the list.
 */
function markOneRead(): void {
    const profile = profiles.value.find((item) => item.id === currentId.value);

    if (profile && profile.unread_alerts_count) {
        profile.unread_alerts_count -= 1;
    }
}

/**
 * Set the unread count of the current profile from a fresher source (the notifications' own counts).
 */
function setUnreadCount(count: number): void {
    const profile = profiles.value.find((item) => item.id === currentId.value);

    if (profile) {
        profile.unread_alerts_count = count;
    }
}

export function useCurrentProfile() {
    const profile = computed(() => profiles.value.find((item) => item.id === currentId.value) ?? null);

    /**
     * The API path of a resource of the current profile: path('invoices/12') is `profiles/1/invoices/12`.
     */
    const path = (resource: string): string => `profiles/${currentId.value}/${resource.replace(/^\/+/, '')}`;

    return {
        profiles: computed(() => profiles.value),
        profile,
        profileId: computed(() => currentId.value),
        settings: computed(() => settings.value),
        path,
        loadProfiles,
        loadSettings,
        select,
        ensure,
        reset,
        markOneRead,
        setUnreadCount,
    };
}
