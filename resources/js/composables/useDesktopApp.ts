import { ref } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, AppEnvironment, RecoveryKit, RestoreChoice, SavedRecoveryKit } from '@/api/types';

/**
 * The desktop app (APP-BUILDS-PLAN.md, phase 3): whether the SPA runs in its window, and the installation's recovery
 * kit, which its owner keeps before working. Not the desktop layout (≥ 1024 px), which `useBreakpoint` tells.
 */
let environment: Promise<AppEnvironment> | null = null;

/**
 * Whether the owner saved or copied the kit, once it's known.
 */
const kitSaved = ref<boolean | null>(null);

/**
 * Whether the SPA runs in the desktop app's window (`GET environment`, asked once). A failure counts as a browser.
 */
async function isDesktopApp(): Promise<boolean> {
    environment ??= api.get<ApiResponse<AppEnvironment>>('environment').then(({ data }) => data);

    try {
        return (await environment).desktop;
    } catch {
        environment = null;

        return false;
    }
}

/**
 * Remember whether the kit was kept, and give it back.
 */
function track<T extends RecoveryKit>(kit: T): T {
    kitSaved.value = kit.saved_at !== null;

    return kit;
}

/**
 * Get the installation's recovery kit.
 */
async function recoveryKit(): Promise<RecoveryKit> {
    const { data } = await api.get<ApiResponse<RecoveryKit>>('desktop/recovery-kit');

    return track(data);
}

/**
 * Save the kit where the owner chooses in a native dialog; `path` is null, when they cancel it.
 */
async function saveRecoveryKit(): Promise<SavedRecoveryKit> {
    const { data } = await api.post<ApiResponse<SavedRecoveryKit>>('desktop/recovery-kit/save');

    return track(data);
}

/**
 * Take the owner's word that they copied the kit somewhere safe.
 */
async function confirmRecoveryKit(): Promise<RecoveryKit> {
    const { data } = await api.post<ApiResponse<RecoveryKit>>('desktop/recovery-kit/confirm');

    return track(data);
}

/**
 * Whether the owner still has to keep the kit: only in the desktop app, and only until they do. A failure asks nothing,
 * as the app shouldn't stop for it.
 */
async function needsRecoveryKit(): Promise<boolean> {
    if (!(await isDesktopApp())) {
        return false;
    }

    if (kitSaved.value === null) {
        await recoveryKit().catch(() => (kitSaved.value = true));
    }

    return kitSaved.value === false;
}

/**
 * Choose the recovery kit (`kit`) or the old computer's database (`database`) in a native open dialog, to restore
 * from; a kit, which isn't one, is a 422.
 */
async function chooseRestoreFile(kind: 'kit' | 'database'): Promise<RestoreChoice> {
    const { data } = await api.post<ApiResponse<RestoreChoice>>(`desktop/restore/${kind}`);

    return data;
}

/**
 * Restore the installation from the kit and the database, which relaunches the app; a key, which doesn't open the
 * database, is a 422.
 */
async function restore(kit: string, database: string): Promise<void> {
    await api.post('desktop/restore', { kit, database });
}

export function useDesktopApp() {
    return { isDesktopApp, needsRecoveryKit, recoveryKit, saveRecoveryKit, confirmRecoveryKit, chooseRestoreFile, restore };
}
