<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { RestoreChoice } from '@/api/types';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { useDesktopApp } from '@/composables/useDesktopApp';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Відновлення: the desktop app's PryTax of another computer (builds.md §4.6), from the new installation's
 * first screen: its recovery kit and its database file, each chosen in a native dialog. A key, which doesn't open the
 * database, is refused before anything changes; once restored, the app relaunches with the old data and key. Only
 * before anyone registered (otherwise back to Вхід).
 */
const router = useRouter();
const { registrationStatus } = useSession();
const { isDesktopApp, chooseRestoreFile, restore } = useDesktopApp();

const chosen = reactive<Record<'kit' | 'database', RestoreChoice | null>>({ kit: null, database: null });
const busy = ref<'kit' | 'database' | 'restore' | null>(null);
const restored = ref(false);
const error = ref<unknown>(null);

onMounted(async () => {
    const status = await registrationStatus().catch(() => null);

    if (!status?.first_owner || !(await isDesktopApp())) {
        await router.replace({ name: 'login' });
    }
});

async function choose(kind: 'kit' | 'database'): Promise<void> {
    busy.value = kind;
    error.value = null;

    try {
        const choice = await chooseRestoreFile(kind);

        if (choice.path) {
            chosen[kind] = choice;
        }
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = null;
    }
}

async function submit(): Promise<void> {
    if (!chosen.kit?.path || !chosen.database?.path) {
        return;
    }

    busy.value = 'restore';
    error.value = null;

    try {
        await restore(chosen.kit.path, chosen.database.path);
        restored.value = true;
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <AuthLayout>
        <div class="flex flex-col gap-4">
            <h1 class="m-0 text-22 font-semibold">{{ t('shell.restore.title') }}</h1>

            <p v-if="restored" class="m-0 text-15 leading-normal text-positive" role="status">{{ t('shell.restore.relaunching') }}</p>

            <template v-else>
                <p class="m-0 text-15 leading-normal text-ink-2">{{ t('shell.restore.explained') }}</p>

                <div class="flex flex-col gap-1.5">
                    <SecondaryButton :busy="busy === 'kit'" @click="choose('kit')">{{ t('shell.restore.chooseKit') }}</SecondaryButton>
                    <span v-if="chosen.kit" class="text-13 break-all text-ink-3">{{ chosen.kit.name }}</span>
                    <span v-if="fieldError(error, 'kit')" class="text-13 text-negative" role="alert">{{ fieldError(error, 'kit') }}</span>
                </div>

                <div class="flex flex-col gap-1.5">
                    <SecondaryButton :busy="busy === 'database'" @click="choose('database')">{{ t('shell.restore.chooseDatabase') }}</SecondaryButton>
                    <span v-if="chosen.database" class="text-13 break-all text-ink-3">{{ chosen.database.name }}</span>
                    <span v-if="fieldError(error, 'database')" class="text-13 text-negative" role="alert">{{ fieldError(error, 'database') }}</span>
                </div>

                <p v-if="error && !fieldError(error, 'kit') && !fieldError(error, 'database')" class="m-0 text-14 text-negative" role="alert">
                    {{ errorMessage(error) }}
                </p>

                <PrimaryButton :disabled="!chosen.kit || !chosen.database" :busy="busy === 'restore'" class="mt-2" @click="submit">
                    {{ t('shell.restore.restore') }}
                </PrimaryButton>
                <RouterLink :to="{ name: 'register' }" class="flex min-h-11 items-center justify-center text-15 font-medium no-underline">
                    {{ t('shell.restore.backToRegister') }}
                </RouterLink>
            </template>
        </div>
    </AuthLayout>
</template>
