<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { RecoveryKit } from '@/api/types';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { useDesktopApp } from '@/composables/useDesktopApp';
import { t } from '@/i18n';
import { kyivTime } from '@/utils/backups';
import { formatDate, kyivDate } from '@/utils/dates';
import { errorMessage } from '@/utils/errors';

/**
 * The installation's recovery kit (builds.md §4.5, §1.4): its key in the desktop app, its recovery code in one
 * person's installation (which sets a new password when it's forgotten), saved to a file (a native dialog in the
 * desktop app, a download in a browser) or copied with the owner's word that it's somewhere safe, and when it was kept. In the setup (`setup`) it ends with
 * continuing (or closing, when it was kept before), which the page handles; in Резервні копії it stays, telling that
 * it's kept.
 */
const { setup = false } = defineProps<{ setup?: boolean }>();
const emit = defineEmits<{ done: [] }>();

const { recoveryKit, saveRecoveryKit, confirmRecoveryKit } = useDesktopApp();

const kit = ref<RecoveryKit | null>(null);
/** Whether the kit was kept before: then the setup closes, rather than continues. */
const keptBefore = ref(false);
const savedPath = ref<string | null>(null);
const copied = ref(false);
const storedSafely = ref(false);
const busy = ref<'load' | 'save' | 'confirm' | null>('load');
const error = ref<unknown>(null);

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

        if (setup) {
            emit('done');

            return;
        }

        kit.value = await recoveryKit();
        copied.value = false;
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <p class="m-0 text-15 leading-normal text-ink-2">{{ kit?.recovery_code ? t('shell.recoveryKit.explainedSingle') : t('shell.recoveryKit.explained') }}</p>
        <pre
            v-if="kit"
            class="m-0 max-h-60 overflow-auto rounded-card border border-line bg-surface p-3 font-mono text-12 leading-relaxed break-all whitespace-pre-wrap select-all"
        >{{ kit.text }}</pre>

        <template v-if="kit && (savedPath || kit.saved_at)">
            <p class="m-0 text-14 text-positive" role="status">
                {{ savedPath ? t('shell.recoveryKit.savedTo', { path: savedPath }) : t('shell.recoveryKit.keptOn', { date: formatDate(kyivDate(kit.saved_at)), time: kyivTime(kit.saved_at) }) }}
            </p>
            <SecondaryButton :busy="busy === 'save'" @click="save">{{ t('shell.recoveryKit.saveToFile') }}</SecondaryButton>
            <PrimaryButton v-if="setup" @click="emit('done')">{{ keptBefore ? t('shell.ui.close') : t('shell.recoveryKit.continue') }}</PrimaryButton>
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
                    {{ setup ? t('shell.recoveryKit.continue') : t('shell.recoveryKit.confirm') }}
                </PrimaryButton>
            </template>
        </template>

        <p v-if="error" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(error) }}</p>
    </div>
</template>
