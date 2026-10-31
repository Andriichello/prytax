import type { Me } from '@/api/types';
import { roleName } from '@/utils/statuses';

/**
 * Whether the e-mail is made up, as one person's installation's (`k3x-9qa-w7m@prytax.invalid`, builds.md §1.4): the
 * reserved `invalid` domain never receives mail, so it means nothing to the person.
 */
export function isMadeUpEmail(email: string): boolean {
    return /@[^@]*invalid$/i.test(email.trim());
}

/**
 * Who is signed in, as the menus show it: the e-mail and the roles; the roles alone with a made-up e-mail; nothing for
 * the only user of one person's installation with a made-up e-mail, who couldn't be anyone else.
 *
 * @param isOnlyUser Whether the installation is one person's with this user alone (`password_only`).
 */
export function signedInAs(user: Pick<Me, 'email' | 'roles'>, isOnlyUser: boolean): string | null {
    const roles = user.roles.map(roleName).join(', ');

    if (!isMadeUpEmail(user.email)) {
        return `${user.email} · ${roles}`;
    }

    return isOnlyUser ? null : roles;
}
