<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import TextField from '@/components/form/TextField.vue';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Вхід: e-mail and password only, and «Запам’ятати мене», which keeps the user signed in past the session (handoff
 * §6.1). Where the installation allows registering a new organization, it links to Реєстрація, and opens it at once
 * on the installation's first launch, which has no user yet (the desktop app's).
 */
const route = useRoute();
const router = useRouter();
const { signIn, registrationStatus } = useSession();

const email = ref('');
const password = ref('');
const remember = ref(true);
const busy = ref(false);
const error = ref<unknown>(null);
const registrationOpen = ref(false);

onMounted(async () => {
    const status = await registrationStatus().catch(() => null);

    if (status?.first_owner) {
        await router.replace({ name: 'register' });

        return;
    }

    registrationOpen.value = status?.enabled ?? false;
});

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        await signIn(email.value.trim(), password.value, remember.value);

        const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/')
            ? route.query.redirect
            : '/';

        await router.replace(redirect);
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AuthLayout>
        <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <TextField
                v-model="email"
                :label="t('shell.login.email')"
                type="email"
                autocomplete="username"
                required
                :error="fieldError(error, 'email')"
            />
            <TextField
                v-model="password"
                :label="t('shell.login.password')"
                type="password"
                autocomplete="current-password"
                required
                :error="fieldError(error, 'password')"
            />
            <div class="flex items-center justify-between gap-3">
                <label class="flex min-h-11 items-center gap-2.5 text-14 text-ink-2">
                    <input v-model="remember" type="checkbox" class="m-0 size-5 accent-accent">
                    {{ t('shell.login.rememberMe') }}
                </label>
                <RouterLink
                    :to="{ name: 'forgot-password', query: email ? { email } : {} }"
                    class="flex min-h-11 items-center text-14 font-medium no-underline"
                >
                    {{ t('shell.login.forgotPassword') }}
                </RouterLink>
            </div>
            <p v-if="error && !fieldError(error, 'email') && !fieldError(error, 'password')" class="m-0 text-14 text-negative" role="alert">
                {{ errorMessage(error) }}
            </p>
            <PrimaryButton type="submit" :busy="busy" class="mt-2">{{ t('shell.login.signIn') }}</PrimaryButton>
            <p v-if="registrationOpen" class="m-0 flex min-h-11 flex-wrap items-center justify-center gap-x-1.5 text-15 text-ink-2">
                {{ t('shell.login.newHere') }}
                <RouterLink :to="{ name: 'register' }" class="font-medium no-underline">{{ t('shell.login.register') }}</RouterLink>
            </p>
        </form>

        <template #footer>
            {{ t('shell.login.accessNote') }}<br>{{ t('shell.login.accountantNote') }}
        </template>
    </AuthLayout>
</template>
