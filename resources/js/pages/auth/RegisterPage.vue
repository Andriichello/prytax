<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { Registration } from '@/api/types';
import TextField from '@/components/form/TextField.vue';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import { useDesktopApp } from '@/composables/useDesktopApp';
import { useInstallationBackups } from '@/composables/useInstallationBackups';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Реєстрація: a new organization and its owner, who is signed in at once, when the installation allows it (otherwise
 * back to Вхід). On the installation's first launch (the desktop app's), Вхід opens it at once, without its way back,
 * and the desktop app offers restoring another computer's PryTax instead. One person's installation (builds.md §1.4)
 * asks for a password alone; its owner keeps the recovery kit next, the only way back in without the password.
 */
const router = useRouter();
const { registrationStatus, register } = useSession();

const form = reactive<Registration>({ organization: '', name: '', email: '', password: '', password_confirmation: '' });
const firstOwner = ref(false);
const restorable = ref(false);
const fullRestorable = ref(false);
/** One person's installation registers with a password alone; its organization and e-mail are made up (builds.md §1.4). */
const single = ref(false);
const busy = ref(false);
const error = ref<unknown>(null);

const FIELDS = ['organization', 'name', 'email', 'password', 'password_confirmation'];

onMounted(async () => {
    const status = await registrationStatus().catch(() => null);

    if (!status?.enabled) {
        await router.replace({ name: 'login' });

        return;
    }

    firstOwner.value = status.first_owner;
    single.value = status.single_organization;
    restorable.value = status.first_owner && (await useDesktopApp().isDesktopApp());

    // A new computer may take another's whole app from a full backup (builds.md §4.11)
    const installation = status.first_owner ? await useInstallationBackups().status().catch(() => null) : null;
    fullRestorable.value = Boolean(installation?.supported && installation.allowed);
});

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        await register({ ...form, email: form.email.trim() });
        await router.replace('/');
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
            <h1 class="m-0 text-22 font-semibold">{{ t('shell.register.title') }}</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">
                {{ single ? t('shell.register.singleHint') : firstOwner ? t('shell.register.firstOwnerHint') : t('shell.register.hint') }}
            </p>
            <template v-if="!single">
                <TextField
                    v-model="form.organization"
                    :label="t('shell.register.organization')"
                    autocomplete="organization"
                    required
                    :error="fieldError(error, 'organization')"
                />
                <TextField
                    v-model="form.name"
                    :label="t('shell.register.name')"
                    autocomplete="name"
                    required
                    :error="fieldError(error, 'name')"
                />
                <TextField
                    v-model="form.email"
                    :label="t('shell.login.email')"
                    type="email"
                    autocomplete="username"
                    required
                    :error="fieldError(error, 'email')"
                />
            </template>
            <TextField
                v-model="form.password"
                :label="t('shell.login.password')"
                type="password"
                autocomplete="new-password"
                required
                :error="fieldError(error, 'password')"
            />
            <TextField
                v-model="form.password_confirmation"
                :label="t('shell.resetPassword.confirmPassword')"
                type="password"
                autocomplete="new-password"
                required
                :error="fieldError(error, 'password_confirmation')"
            />
            <p v-if="error && !FIELDS.some((field) => fieldError(error, field))" class="m-0 text-14 text-negative" role="alert">
                {{ errorMessage(error) }}
            </p>
            <PrimaryButton type="submit" :busy="busy" class="mt-2">{{ t('shell.register.submit') }}</PrimaryButton>
            <RouterLink v-if="restorable" :to="{ name: 'restore' }" class="flex min-h-11 items-center justify-center text-15 font-medium no-underline">
                {{ t('shell.restore.link') }}
            </RouterLink>
            <RouterLink v-if="fullRestorable" :to="{ name: 'restore-full' }" class="flex min-h-11 items-center justify-center text-15 font-medium no-underline">
                {{ t('shell.restore.fullLink') }}
            </RouterLink>
            <p v-if="!firstOwner" class="m-0 flex min-h-11 flex-wrap items-center justify-center gap-x-1.5 text-15 text-ink-2">
                {{ t('shell.register.haveAccount') }}
                <RouterLink :to="{ name: 'login' }" class="font-medium no-underline">{{ t('shell.login.signIn') }}</RouterLink>
            </p>
        </form>
    </AuthLayout>
</template>
