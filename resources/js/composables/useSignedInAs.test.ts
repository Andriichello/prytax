import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Me } from '@/api/types';

/**
 * Who is signed in, as the menus show it: with a made-up e-mail, nothing until the installation tells whether this is
 * its only user, then nothing for them, or the roles while others may sign in too. The session keeps its user, so each
 * test imports it afresh, signed in with the e-mail.
 */
async function signedInAsFor(email: string, passwordOnly: boolean) {
    vi.resetModules();
    const { api } = await import('@/api/client');
    const me = { email, roles: ['owner'], locale: 'uk', permissions: [] } as unknown as Me;
    const registration = { enabled: false, first_owner: false, single_organization: true, password_only: passwordOnly };
    vi.spyOn(api, 'get').mockImplementation(async (path: string) => ({ data: path === 'auth/me' ? me : registration, message: 'OK' }));
    const { useSession } = await import('@/composables/useSession');
    await useSession().load();
    const { useSignedInAs } = await import('@/composables/useSignedInAs');

    return useSignedInAs();
}

describe('useSignedInAs', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('shows nothing for the only user of one person\'s installation, nor while it asks', async () => {
        const signedInAs = await signedInAsFor('4jl-cud-0ca@prytax.invalid', true);

        expect(signedInAs.value).toBeNull();

        await new Promise((resolve) => setTimeout(resolve));

        expect(signedInAs.value).toBeNull();
    });

    it('shows the roles once others may sign in too', async () => {
        const signedInAs = await signedInAsFor('4jl-cud-0ca@prytax.invalid', false);

        await new Promise((resolve) => setTimeout(resolve));

        expect(signedInAs.value).toBe('власник');
    });
});
