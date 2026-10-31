import { ref } from 'vue';
import { api } from '@/api/client';
import type { ApiResponse, AppEnvironment, RecoveryKit, RestoreChoice, SavedRecoveryKit } from '@/api/types';

/**
 * The desktop app (builds.md §4.2): whether the SPA runs in its window, and the installation's recovery
 * kit, which its owner keeps before working, as does one person's installation (builds.md §1.4) in a browser. Not the desktop layout (≥ 600 px), which `useBreakpoint` tells.
 */
let environment: Promise<AppEnvironment> | null = null;

/**
 * Whether the owner saved or copied the kit, once it's known.
 */
const kitSaved = ref<boolean | null>(null);

/**
 * The installation's environment (`GET environment`, asked once), or null when it can't be read.
 */
async function environmentOf(): Promise<AppEnvironment | null> {
    environment ??= api.get<ApiResponse<AppEnvironment>>('environment').then(({ data }) => data);

    try {
        return await environment;
    } catch {
        environment = null;

        return null;
    }
}

/**
 * Whether the SPA runs in the desktop app's window. A failure counts as a browser.
 */
async function isDesktopApp(): Promise<boolean> {
    return (await environmentOf())?.desktop ?? false;
}

/**
 * Whether the installation is one person's (builds.md §1.4), whose owner keeps a recovery kit in a browser too.
 */
async function isSingleOrganization(): Promise<boolean> {
    return (await environmentOf())?.single_organization ?? false;
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
 * Save the kit: where the owner chooses in the desktop app's native dialog (`path` is null, when they cancel it); a
 * browser downloads it, and the kit is taken as kept (`path` is its file name).
 */
async function saveRecoveryKit(): Promise<SavedRecoveryKit> {
    if (await isDesktopApp()) {
        const { data } = await api.post<ApiResponse<SavedRecoveryKit>>('desktop/recovery-kit/save');

        return track(data);
    }

    const kit = await recoveryKit();
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([kit.text], { type: 'text/plain;charset=utf-8' }));
    link.download = kit.filename;
    link.click();
    URL.revokeObjectURL(link.href);

    const { data } = await api.post<ApiResponse<RecoveryKit>>('desktop/recovery-kit/confirm', { via: 'file' });

    return track({ ...data, path: kit.filename });
}

/**
 * Take the owner's word that they copied the kit somewhere safe.
 */
async function confirmRecoveryKit(): Promise<RecoveryKit> {
    const { data } = await api.post<ApiResponse<RecoveryKit>>('desktop/recovery-kit/confirm');

    return track(data);
}

/**
 * Whether the owner still has to keep the kit: in the desktop app or one person's installation, until they do. A
 * failure (an installation without a kit) asks nothing, as the app shouldn't stop for it.
 */
async function needsRecoveryKit(): Promise<boolean> {
    if (!(await isDesktopApp()) && !(await isSingleOrganization())) {
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
    return { isDesktopApp, isSingleOrganization, needsRecoveryKit, recoveryKit, saveRecoveryKit, confirmRecoveryKit, chooseRestoreFile, restore };
}
