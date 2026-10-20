<script setup lang="ts">
import { ref } from 'vue';
import { useRoute } from 'vue-router';
import { api, fetchCsrfCookie } from '@/api/client';
import TextField from '@/components/form/TextField.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * The page of the e-mailed reset link (`/reset-password?token=…&email=…`): set a new password, then sign in with it.
 */
const route = useRoute();

const token = typeof route.query.token === 'string' ? route.query.token : '';
const email = typeof route.query.email === 'string' ? route.query.email : '';

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
        await api.post('auth/reset-password', {
            token,
            email,
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
            <h1 class="m-0 text-22 font-semibold">Пароль змінено</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">Увійдіть з новим паролем. Інші сесії цього облікового запису завершено.</p>
            <RouterLink :to="{ name: 'login' }" class="flex min-h-11 items-center text-15 font-medium no-underline">Увійти</RouterLink>
        </div>

        <div v-else-if="!token || !email" class="flex flex-col gap-4">
            <h1 class="m-0 text-22 font-semibold">Посилання неповне</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">Відкрийте посилання з листа повністю або попросіть нове.</p>
            <RouterLink :to="{ name: 'forgot-password' }" class="flex min-h-11 items-center text-15 font-medium no-underline">
                Надіслати нове посилання
            </RouterLink>
        </div>

        <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
            <h1 class="m-0 text-22 font-semibold">Новий пароль</h1>
            <p class="m-0 text-15 text-ink-2">для {{ email }}</p>
            <TextField
                v-model="password"
                label="Новий пароль"
                type="password"
                autocomplete="new-password"
                required
                :error="fieldError(error, 'password')"
            />
            <TextField
                v-model="confirmation"
                label="Повторіть пароль"
                type="password"
                autocomplete="new-password"
                required
            />
            <p v-if="error && !fieldError(error, 'password')" class="m-0 text-14 text-negative" role="alert">
                {{ fieldError(error, 'email') ?? errorMessage(error) }}
            </p>
            <PrimaryButton type="submit" :busy="busy" class="mt-2">Зберегти пароль</PrimaryButton>
        </form>
    </AuthLayout>
</template>
