<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import TextField from '@/components/form/TextField.vue';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * A new password, when someone else set the current one (api-core's required password update): until it is changed,
 * the API refuses every other request, so the app sends the person here first.
 */
const route = useRoute();
const router = useRouter();
const { user, load, signOut } = useSession();

const current = ref('');
const password = ref('');
const confirmation = ref('');
const busy = ref(false);
const error = ref<unknown>(null);

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        await api.post('auth/change-password', {
            current_password: current.value,
            password: password.value,
            password_confirmation: confirmation.value,
        });
        await load(true);

        const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/';

        await router.replace(redirect);
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = false;
    }
}

async function leave(): Promise<void> {
    await signOut();
    await router.replace({ name: 'login' });
}
</script>

<template>
    <AuthLayout>
        <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <h1 class="m-0 text-22 font-semibold">{{ t('shell.changePassword.title') }}</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">
                {{ t('shell.changePassword.explained', { email: user?.email ?? '' }) }}
            </p>
            <TextField
                v-model="current"
                :label="t('shell.changePassword.currentPassword')"
                type="password"
                autocomplete="current-password"
                required
                :error="fieldError(error, 'current_password')"
            />
            <TextField
                v-model="password"
                :label="t('shell.resetPassword.newPassword')"
                type="password"
                autocomplete="new-password"
                required
                :error="fieldError(error, 'password')"
            />
            <TextField
                v-model="confirmation"
                :label="t('shell.changePassword.confirmNewPassword')"
                type="password"
                autocomplete="new-password"
                required
            />
            <p v-if="error && !fieldError(error, 'current_password') && !fieldError(error, 'password')" class="m-0 text-14 text-negative" role="alert">
                {{ errorMessage(error) }}
            </p>
            <PrimaryButton type="submit" :busy="busy" class="mt-2">{{ t('shell.resetPassword.savePassword') }}</PrimaryButton>
            <button type="button" class="flex min-h-11 items-center self-center text-15 font-medium text-accent" @click="leave">{{ t('shell.more.signOut') }}</button>
        </form>
    </AuthLayout>
</template>
