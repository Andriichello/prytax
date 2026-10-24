<script setup lang="ts">
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import { api, fetchCsrfCookie } from '@/api/client';
import TextField from '@/components/form/TextField.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import { t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * The page of the e-mailed invitation (`/accept-invitation?id=…&hash=…&expires=…&signature=…`): the invited person
 * chooses a password, then signs in with it.
 */
const route = useRoute();

const param = (name: string): string => (typeof route.query[name] === 'string' ? route.query[name] : '');
const link = { id: param('id'), hash: param('hash'), expires: param('expires'), signature: param('signature') };
const isComplete = Object.values(link).every((value) => value !== '');

const password = ref('');
const confirmation = ref('');
const busy = ref(false);
const done = ref(false);
const error = ref<unknown>(null);

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        await fetchCsrfCookie();
        await api.post('auth/accept-invitation', {
            ...link,
            password: password.value,
            password_confirmation: confirmation.value,
        });
        done.value = true;
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout>
        <div v-if="done" class="flex flex-col gap-4">
            <h1 class="m-0 text-22 font-semibold">{{ t('shell.invitation.invitationAccepted') }}</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">{{ t('shell.invitation.acceptedHint') }}</p>
            <RouterLink :to="{ name: 'login' }" class="flex min-h-11 items-center text-15 font-medium no-underline">{{ t('shell.login.signIn') }}</RouterLink>
        </div>

        <div v-else-if="!isComplete" class="flex flex-col gap-4">
            <h1 class="m-0 text-22 font-semibold">{{ t('shell.resetPassword.linkIncomplete') }}</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">{{ t('shell.invitation.openWholeLink') }}</p>
        </div>

        <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <h1 class="m-0 text-22 font-semibold">{{ t('shell.invitation.title') }}</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">{{ t('shell.invitation.hint') }}</p>
            <TextField
                v-model="password"
                :label="t('shell.login.password')"
                type="password"
                autocomplete="new-password"
                required
                :error="fieldError(error, 'password')"
            />
            <TextField
                v-model="confirmation"
                :label="t('shell.resetPassword.confirmPassword')"
                type="password"
                autocomplete="new-password"
                required
            />
            <p v-if="error && !fieldError(error, 'password')" class="m-0 text-14 text-negative" role="alert">
                {{ errorMessage(error) }}
            </p>
            <PrimaryButton type="submit" :busy="busy" class="mt-2">{{ t('shell.invitation.join') }}</PrimaryButton>
        </form>
    </AuthLayout>
</template>
