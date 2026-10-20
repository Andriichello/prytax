<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import TextField from '@/components/form/TextField.vue';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { useSession } from '@/composables/useSession';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Вхід: e-mail and password only (handoff §6.1).
 */
const route = useRoute();
const router = useRouter();
const { signIn } = useSession();

const email = ref('');
const password = ref('');
const busy = ref(false);
const error = ref<unknown>(null);

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        await signIn(email.value.trim(), password.value);

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
                label="Електронна пошта"
                type="email"
                autocomplete="username"
                required
                :error="fieldError(error, 'email')"
            />
            <TextField
                v-model="password"
                label="Пароль"
                type="password"
                autocomplete="current-password"
                required
                :error="fieldError(error, 'password')"
            />
            <div class="flex justify-end">
                <RouterLink
                    :to="{ name: 'forgot-password', query: email ? { email } : {} }"
                    class="flex min-h-11 items-center text-14 font-medium no-underline"
                >
                    Забули пароль?
                </RouterLink>
            </div>
            <p v-if="error && !fieldError(error, 'email') && !fieldError(error, 'password')" class="m-0 text-14 text-negative" role="alert">
                {{ errorMessage(error) }}
            </p>
            <PrimaryButton type="submit" :busy="busy" class="mt-2">Увійти</PrimaryButton>
        </form>

        <template #footer>
            Доступ надає власник організації.<br>Бухгалтер бачить усі ФОП своєї практики.
        </template>
    </AuthLayout>
</template>
