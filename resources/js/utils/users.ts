import type { Me } from '@/api/types';
import { roleName } from '@/utils/statuses';

/**
 * Whether the e-mail is made up, as one person's installation's (`k3x-9qa-w7m@prytax.invalid`, builds.md §1.4): the
 * reserved `invalid` domain never receives mail, so it means nothing to the person.
 */
export function isMadeUpEmail(email: string): boolean {
    return /@[^@]*invalid$/i.test(email.trim());
}

/** Who is signed in, as the menus show it: the e-mail and the roles, or the roles alone with a made-up e-mail. */
export function signedInAs(user: Pick<Me, 'email' | 'roles'>): string {
    const roles = user.roles.map(roleName).join(', ');

    return isMadeUpEmail(user.email) ? roles : `${user.email} · ${roles}`;
}
