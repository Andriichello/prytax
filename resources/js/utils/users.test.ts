import { describe, expect, it } from 'vitest';
import { signedInAs } from '@/utils/users';

describe('signedInAs', () => {
    it('shows the e-mail and the roles', () => {
        expect(signedInAs({ email: 'olena@example.com', roles: ['owner'] })).toBe('olena@example.com · власник');
    });

    it('shows the roles alone with a made-up e-mail of one person\'s installation', () => {
        expect(signedInAs({ email: '4jl-cud-0ca@prytax.invalid', roles: ['owner'] })).toBe('власник');
    });
});
