import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Whether the owner still has to keep the recovery kit: only in the desktop app, and only until they keep it; a
 * failure asks nothing. The composable remembers the answers, so each test imports it afresh.
 */
async function freshDesktopApp() {
    vi.resetModules();
    const { api: freshApi } = await import('@/api/client');
    const { useDesktopApp } = await import('@/composables/useDesktopApp');

    return { api: freshApi, ...useDesktopApp() };
}

const kit = (savedAt: string | null) => ({ data: { text: 'PryTax recovery kit', filename: 'kit.txt', saved_at: savedAt }, message: 'OK' });

describe('useDesktopApp', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('asks nothing in a browser, without asking for the kit', async () => {
        const app = await freshDesktopApp();
        const get = vi.spyOn(app.api, 'get').mockResolvedValue({ data: { desktop: false }, message: 'OK' });

        expect(await app.needsRecoveryKit()).toBe(false);
        expect(get).toHaveBeenCalledTimes(1);
        expect(get).toHaveBeenCalledWith('environment');
    });

    it('asks for the kit in the desktop app until the owner keeps it', async () => {
        const app = await freshDesktopApp();
        vi.spyOn(app.api, 'get').mockImplementation(async (path: string) => (path === 'environment' ? { data: { desktop: true }, message: 'OK' } : kit(null)));
        vi.spyOn(app.api, 'post').mockResolvedValue(kit('2026-10-29T21:00:00+02:00'));

        expect(await app.needsRecoveryKit()).toBe(true);

        await app.confirmRecoveryKit();

        expect(await app.needsRecoveryKit()).toBe(false);
    });

    it('asks nothing when the kit can’t be loaded', async () => {
        const app = await freshDesktopApp();
        vi.spyOn(app.api, 'get').mockImplementation(async (path: string) => {
            if (path === 'environment') {
                return { data: { desktop: true }, message: 'OK' };
            }

            throw new Error('offline');
        });

        expect(await app.needsRecoveryKit()).toBe(false);
    });
});
