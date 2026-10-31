<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, fetchCsrfCookie } from '@/api/client';
import TextField from '@/components/form/TextField.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import Translated from '@/components/ui/Translated.vue';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * "Забули пароль?": e-mail a link to the reset page. The answer is the same whether the address has an account or not.
 * One person's installation (builds.md §1.4), which sends no mail, sets the new password here instead, with its
 * recovery code or the installation's key, and signs in.
 */
const route = useRoute();
const router = useRouter();
const { registrationStatus, resetWithRecovery } = useSession();

/** One person's installation with one user: its recovery code (or key) sets the password. */
const recovery = ref(false);
const secret = ref('');
const password = ref('');
const confirmation = ref('');

onMounted(async () => {
    recovery.value = (await registrationStatus().catch(() => null))?.password_only ?? false;
});

async function recover(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        await resetWithRecovery(secret.value.trim(), password.value, confirmation.value);
        await router.replace('/');
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = false;
    }
}

const email = ref(typeof route.query.email === 'string' ? route.query.email : '');
const busy = ref(false);
const sent = ref(false);
const error = ref<unknown>(null);

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        await fetchCsrfCookie();
        await api.post('auth/forgot-password', { email: email.value.trim() });
        sent.value = true;
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout>
        <div v-if="sent" class="flex flex-col gap-4">
            <h1 class="m-0 text-22 font-semibold">{{ t('shell.forgotPassword.checkEmail') }}</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">
                <Translated :text="t('shell.forgotPassword.sent')">
                    <template #email><span class="font-medium text-ink">{{ email }}</span></template>
                </Translated>
            </p>
            <RouterLink :to="{ name: 'login' }" class="flex min-h-11 items-center text-15 font-medium no-underline">
                {{ t('shell.forgotPassword.backToSignIn') }}
            </RouterLink>
        </div>

        <form v-else-if="recovery" class="flex flex-col gap-4" novalidate @submit.prevent="recover">
            <h1 class="m-0 text-22 font-semibold">{{ t('shell.forgotPassword.recoveryTitle') }}</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">{{ t('shell.forgotPassword.recoveryHint') }}</p>
            <TextField
                v-model="secret"
                :label="t('shell.forgotPassword.secret')"
                :hint="t('shell.forgotPassword.secretHint')"
                autocomplete="off"
                required
                :error="fieldError(error, 'secret')"
            />
            <TextField
                v-model="password"
                :label="t('shell.resetPassword.newPassword')"
                type="password"
                autocomplete="new-password"
                required
                :error="fieldError(error, 'password')"
            />
            <TextField v-model="confirmation" :label="t('shell.resetPassword.confirmPassword')" type="password" autocomplete="new-password" required />
            <p v-if="error && !fieldError(error, 'secret') && !fieldError(error, 'password')" class="m-0 text-14 text-negative" role="alert">
                {{ errorMessage(error) }}
            </p>
            <PrimaryButton type="submit" :busy="busy" class="mt-2">{{ t('shell.forgotPassword.setPassword') }}</PrimaryButton>
            <RouterLink :to="{ name: 'login' }" class="flex min-h-11 items-center justify-center text-15 font-medium no-underline">
                {{ t('shell.forgotPassword.backToSignIn') }}
            </RouterLink>
        </form>

        <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <h1 class="m-0 text-22 font-semibold">{{ t('shell.forgotPassword.title') }}</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">{{ t('shell.forgotPassword.hint') }}</p>
            <TextField
                v-model="email"
                :label="t('shell.login.email')"
                type="email"
                autocomplete="username"
                required
                :error="fieldError(error, 'email')"
            />
            <p v-if="error && !fieldError(error, 'email')" class="m-0 text-14 text-negative" role="alert">
                {{ errorMessage(error) }}
            </p>
            <PrimaryButton type="submit" :busy="busy" class="mt-2">{{ t('shell.forgotPassword.sendLink') }}</PrimaryButton>
            <RouterLink :to="{ name: 'login' }" class="flex min-h-11 items-center justify-center text-15 font-medium no-underline">
                {{ t('shell.forgotPassword.backToSignIn') }}
            </RouterLink>
        </form>
    </AuthLayout>
</template>
