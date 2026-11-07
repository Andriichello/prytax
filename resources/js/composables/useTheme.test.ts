import { afterEach, describe, expect, it, vi } from 'vitest';

/**
 * The theme comes from this device or follows the operating system, and reaches `<html data-theme>` and the browser's
 * bars. The module reads the device when imported, so each test imports it afresh, on a device of its own.
 */
async function freshTheme(options: { stored?: string | null; systemIsDark?: boolean; storageFails?: boolean } = {}) {
    const stored = new Map<string, string>(options.stored ? [['prytax.theme', options.stored]] : []);
    const listeners: ((event: { matches: boolean }) => void)[] = [];
    const bar = { setAttribute: vi.fn() };
    const html = { dataset: {} as Record<string, string> };

    vi.stubGlobal('window', {
        localStorage: {
            getItem: (key: string) => {
                if (options.storageFails) {
                    throw new Error('blocked');
                }

                return stored.get(key) ?? null;
            },
            setItem: (key: string, value: string) => {
                if (options.storageFails) {
                    throw new Error('blocked');
                }

                stored.set(key, value);
            },
        },
        matchMedia: () => ({
            matches: options.systemIsDark ?? false,
            addEventListener: (_: string, listener: (event: { matches: boolean }) => void) => listeners.push(listener),
        }),
    });
    vi.stubGlobal('document', { documentElement: html, querySelector: () => bar, createElement: () => ({}) });

    vi.resetModules();

    const module = await import('@/composables/useTheme');

    return { ...module, stored, html, bar, systemChanges: (matches: boolean) => listeners.forEach((listener) => listener({ matches })) };
}

/**
 * The same with the session on top, whose calls to the API each test answers.
 */
async function freshSession(options: Parameters<typeof freshTheme>[0] = {}) {
    const device = await freshTheme(options);
    const { api } = await import('@/api/client');
    const { useSession } = await import('@/composables/useSession');

    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({}));

    return { ...device, api, ...useSession() };
}

const me = (theme?: string) => ({ id: 1, locale: 'uk', metadata: theme ? { theme } : null, permissions: [] });

describe('useTheme', () => {
    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('follows the operating system until a theme is chosen, also when it changes', async () => {
        const { theme, html, bar, systemChanges } = await freshTheme({ systemIsDark: true });

        expect(theme.value).toBe('system');
        expect(html.dataset.theme).toBe('dark');
        expect(bar.setAttribute).toHaveBeenLastCalledWith('content', '#191A1C');

        systemChanges(false);

        expect(html.dataset.theme).toBe('light');
        expect(bar.setAttribute).toHaveBeenLastCalledWith('content', '#F4F3EE');
    });

    it('keeps a chosen theme whatever the operating system says', async () => {
        const { html, systemChanges } = await freshTheme({ stored: 'dark', systemIsDark: false });

        expect(html.dataset.theme).toBe('dark');

        systemChanges(false);

        expect(html.dataset.theme).toBe('dark');
    });

    it('ignores what it doesn\'t know in the storage', async () => {
        const { theme } = await freshTheme({ stored: 'sepia' });

        expect(theme.value).toBe('system');
    });

    it('applies a choice at once and remembers it on the device', async () => {
        const { setTheme, html, stored } = await freshTheme();

        setTheme('dark');

        expect(html.dataset.theme).toBe('dark');
        expect(stored.get('prytax.theme')).toBe('dark');
    });

    it('still applies a choice when the storage is blocked', async () => {
        const { setTheme, html } = await freshTheme({ storageFails: true });

        setTheme('dark');

        expect(html.dataset.theme).toBe('dark');
    });
});

describe('a user\'s theme', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('applies on signing in and is remembered on the device', async () => {
        const { api, load, html, stored } = await freshSession();

        vi.spyOn(api, 'get').mockResolvedValue({ data: me('dark') } as never);
        await load();

        expect(html.dataset.theme).toBe('dark');
        expect(stored.get('prytax.theme')).toBe('dark');
    });

    it('leaves the device\'s theme to a user who chose none', async () => {
        const { api, load, theme } = await freshSession({ stored: 'dark' });

        vi.spyOn(api, 'get').mockResolvedValue({ data: me() } as never);
        await load();

        expect(theme.value).toBe('dark');
    });

    it('is saved on the user when they choose it, and only when it changes', async () => {
        const { api, load, switchTheme, html } = await freshSession();

        vi.spyOn(api, 'get').mockResolvedValue({ data: me('light') } as never);
        const patch = vi.spyOn(api, 'patch').mockResolvedValue({ data: me('dark') } as never);
        await load();

        await switchTheme('dark');

        expect(patch).toHaveBeenCalledExactlyOnceWith('auth/me/theme', { theme: 'dark' });
        expect(html.dataset.theme).toBe('dark');

        await switchTheme('dark');

        expect(patch).toHaveBeenCalledOnce();
    });

    it('chosen before signing in becomes the user\'s own, over the one they had', async () => {
        const { api, switchTheme, signIn, html } = await freshSession();

        vi.spyOn(api, 'post').mockResolvedValue({ data: { user: me('light') } } as never);
        const patch = vi.spyOn(api, 'patch').mockResolvedValue({ data: me('dark') } as never);

        await switchTheme('dark');

        expect(patch).not.toHaveBeenCalled();

        await signIn('olena@example.com', 'secret');

        expect(patch).toHaveBeenCalledExactlyOnceWith('auth/me/theme', { theme: 'dark' });
        expect(html.dataset.theme).toBe('dark');
    });
});
