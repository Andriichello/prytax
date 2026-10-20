<script setup lang="ts">
import { useRouter } from 'vue-router';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useSession } from '@/composables/useSession';

/**
 * An organization without any ФОП yet, for people who can't create one (owners are sent to the new ФОП's form).
 */
const router = useRouter();
const { user, signOut } = useSession();

async function leave(): Promise<void> {
    await signOut();
    useCurrentProfile().reset();
    await router.replace({ name: 'login' });
}
</script>

<template>
    <AuthLayout>
        <div class="flex flex-col gap-4">
            <h1 class="m-0 text-22 font-semibold">Ще немає жодного ФОП</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">
                Власник організації має додати ФОП, перш ніж {{ user?.name ?? 'ви' }} зможете працювати з обліком.
            </p>
            <button type="button" class="flex min-h-11 items-center self-start text-15 font-medium text-accent" @click="leave">Вийти</button>
        </div>
    </AuthLayout>
</template>
