<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { RecoveryKit } from '@/api/types';
import AuthLayout from '@/components/layout/AuthLayout.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { useDesktopApp } from '@/composables/useDesktopApp';
import { t } from '@/i18n';
import { kyivTime } from '@/utils/backups';
import { formatDate, kyivDate } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';

/**
 * Набір відновлення: the desktop installation's key (builds.md §4.5), which the owner keeps away from the
 * computer before working: saved to a file through a native dialog, or copied with their word that it's somewhere
 * safe. Without it a copy of the database can't be read. Opened again from Резервні копії, it tells when it was kept,
 * downloads it again, and closes back to where it was opened from.
 */
const route = useRoute();
const router = useRouter();
const { recoveryKit, saveRecoveryKit, confirmRecoveryKit } = useDesktopApp();

const kit = ref<RecoveryKit | null>(null);
/** Whether the kit was kept before this visit: then the page closes, rather than continuing the setup. */
const keptBefore = ref(false);
const savedPath = ref<string | null>(null);
const copied = ref(false);
const storedSafely = ref(false);
const busy = ref<'load' | 'save' | 'confirm' | null>('load');
const error = ref<unknown>(null);

const redirect = computed(() => (typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/'));

onMounted(async () => {
    try {
        kit.value = await recoveryKit();
        keptBefore.value = kit.value.saved_at !== null;
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = null;
    }
});

async function save(): Promise<void> {
    busy.value = 'save';
    error.value = null;

    try {
        const saved = await saveRecoveryKit();

        kit.value = saved;
        savedPath.value = saved.path;
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = null;
    }
}

async function copy(): Promise<void> {
    if (!kit.value) {
        return;
    }

    await navigator.clipboard.writeText(kit.value.text);
    copied.value = true;
}

async function confirm(): Promise<void> {
    busy.value = 'confirm';
    error.value = null;

    try {
        await confirmRecoveryKit();
        await router.replace(redirect.value);
    } catch (caught) {
        error.value = caught;
        busy.value = null;
    }
}
</script>

<template>
    <AuthLayout>
        <div class="flex flex-col gap-4">
            <h1 class="m-0 text-22 font-semibold">{{ t('shell.recoveryKit.title') }}</h1>
            <p class="m-0 text-15 leading-normal text-ink-2">{{ t('shell.recoveryKit.explained') }}</p>

            <pre
                v-if="kit"
                class="m-0 max-h-60 overflow-auto rounded-card border border-line bg-surface p-3 font-mono text-12 leading-relaxed break-all whitespace-pre-wrap select-all"
            >{{ kit.text }}</pre>

            <template v-if="kit && (savedPath || kit.saved_at)">
                <p class="m-0 text-14 text-positive" role="status">
                    {{ savedPath ? t('shell.recoveryKit.savedTo', { path: savedPath }) : t('shell.recoveryKit.keptOn', { date: formatDate(kyivDate(kit.saved_at)), time: kyivTime(kit.saved_at) }) }}
                </p>
                <SecondaryButton :busy="busy === 'save'" @click="save">{{ t('shell.recoveryKit.saveToFile') }}</SecondaryButton>
                <PrimaryButton @click="router.replace(redirect)">{{ keptBefore ? t('shell.ui.close') : t('shell.recoveryKit.continue') }}</PrimaryButton>
            </template>

            <template v-else-if="kit">
                <PrimaryButton :busy="busy === 'save'" @click="save">{{ t('shell.recoveryKit.saveToFile') }}</PrimaryButton>
                <SecondaryButton @click="copy">{{ copied ? t('shell.recoveryKit.copied') : t('shell.recoveryKit.copy') }}</SecondaryButton>
                <template v-if="copied">
                    <label class="flex min-h-11 items-start gap-2.5 text-14 text-ink-2">
                        <input v-model="storedSafely" type="checkbox" class="m-0 mt-0.5 size-5 shrink-0 accent-accent">
                        {{ t('shell.recoveryKit.storedSafely') }}
                    </label>
                    <PrimaryButton :disabled="!storedSafely" :busy="busy === 'confirm'" @click="confirm">
                        {{ t('shell.recoveryKit.continue') }}
                    </PrimaryButton>
                </template>
            </template>

            <p v-if="error" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(error) }}</p>
        </div>
    </AuthLayout>
</template>
