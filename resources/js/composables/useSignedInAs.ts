import { computed, ref, watch, type ComputedRef } from 'vue';
import { useSession } from '@/composables/useSession';
import { isMadeUpEmail, signedInAs } from '@/utils/users';

/**
 * Who is signed in, as the menus show it above signing out (`signedInAs`), or null when there's nothing to show. With
 * a made-up e-mail it asks whether this is one person's installation with this user alone (`GET registration`'s
 * `password_only`), and shows nothing until it knows, so the role doesn't flash.
 */
export function useSignedInAs(): ComputedRef<string | null> {
    const { user, registrationStatus } = useSession();
    const isOnlyUser = ref<boolean | null>(null);

    watch(
        () => user.value?.email,
        async (email) => {
            isOnlyUser.value = null;

            if (email !== undefined && isMadeUpEmail(email)) {
                isOnlyUser.value = (await registrationStatus().catch(() => null))?.password_only ?? false;
            }
        },
        { immediate: true },
    );

    return computed(() => {
        if (user.value === null || (isMadeUpEmail(user.value.email) && isOnlyUser.value === null)) {
            return null;
        }

        return signedInAs(user.value, isOnlyUser.value ?? false);
    });
}
