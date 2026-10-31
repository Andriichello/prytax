import { describe, expect, it } from 'vitest';
import { signedInAs } from '@/utils/users';

describe('signedInAs', () => {
    it('shows the e-mail and the roles', () => {
        expect(signedInAs({ email: 'olena@example.com', roles: ['owner'] }, false)).toBe('olena@example.com · власник');
    });

    it('shows the roles alone with a made-up e-mail, while others may sign in too', () => {
        expect(signedInAs({ email: '4jl-cud-0ca@prytax.invalid', roles: ['owner'] }, false)).toBe('власник');
    });

    it('shows nothing for the only user of one person\'s installation, whose e-mail is made up', () => {
        expect(signedInAs({ email: '4jl-cud-0ca@prytax.invalid', roles: ['owner'] }, true)).toBeNull();
    });
});
