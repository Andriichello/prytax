import { api, fetchCsrfCookie } from '@/api/client';
import type { ApiResponse, InstallationBackupSaved, InstallationComparison, InstallationStatus } from '@/api/types';
import { uploadInPieces } from '@/api/uploads';

/**
 * Full backups of the installation (builds.md §4.11): whether they're offered here, saving one (into a file chosen in
 * the desktop app's dialog, else downloaded), and restoring one in this installation's place: chosen in the desktop
 * app's dialog or uploaded in pieces, as a web app's PHP takes small uploads, then compared, then restored.
 */
export function useInstallationBackups() {
    /** Whether full backups are offered here, and to this person (`GET installation`). */
    async function status(): Promise<InstallationStatus> {
        const { data } = await api.get<ApiResponse<InstallationStatus>>('installation');

        return data;
    }

    /**
     * Save a full backup with its password (`POST installation/backups`): the desktop app asks where; a browser
     * downloads it. A restore's token marks this installation's state as saved for it.
     */
    async function save(password: string, confirmation: string, restore?: string): Promise<InstallationBackupSaved> {
        await fetchCsrfCookie();

        const { data } = await api.post<ApiResponse<InstallationBackupSaved>>('installation/backups', {
            password,
            password_confirmation: confirmation,
            restore,
        });

        if (data.download_url) {
            window.location.assign(data.download_url);
        }

        return data;
    }

    /** Choose a full backup in the desktop app's native dialog (`POST installation/restores/choose`). */
    async function choose(): Promise<{ path: string | null; name: string | null }> {
        await fetchCsrfCookie();

        const { data } = await api.post<ApiResponse<{ path: string | null; name: string | null }>>('installation/restores/choose');

        return data;
    }

    /** Upload a full backup in pieces, telling the share sent, and get the upload's id. */
    async function upload(file: File, progress: (share: number) => void): Promise<string> {
        await fetchCsrfCookie();

        return uploadInPieces('installation/uploads', file, progress);
    }

    /** Open a full backup with its password and compare it with this installation (`POST installation/restores`). */
    async function compare(source: { upload: string } | { path: string }, password: string): Promise<InstallationComparison> {
        const { data } = await api.post<ApiResponse<InstallationComparison>>('installation/restores', { ...source, password });

        return data;
    }

    /** Restore an opened backup in this installation's place (`POST installation/restores/{token}`). */
    async function restore(token: string): Promise<{ restored: boolean; relaunched: boolean }> {
        const { data } = await api.post<ApiResponse<{ restored: boolean; relaunched: boolean }>>(`installation/restores/${token}`);

        return data;
    }

    /** Drop an opened backup (`DELETE installation/restores/{token}`). */
    async function cancel(token: string): Promise<void> {
        await api.delete(`installation/restores/${token}`);
    }

    return { status, save, choose, upload, compare, restore, cancel };
}
