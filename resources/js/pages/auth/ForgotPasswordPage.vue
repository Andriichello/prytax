<script setup lang="ts">
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import { api, fetchCsrfCookie } from '@/api/client';
import TextField from '@/components/form/TextField.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import Translated from '@/components/ui/Translated.vue';
import { t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * "Забули пароль?": e-mail a link to the reset page. The answer is the same whether the address has an account or not.
 */
const route = useRoute();

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
