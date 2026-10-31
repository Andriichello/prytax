<script setup lang="ts">
import { ref } from 'vue';
import TextField from '@/components/form/TextField.vue';
import Icon from '@/components/ui/Icon.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { useInstallationBackups } from '@/composables/useInstallationBackups';
import { t } from '@/i18n';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Saving a full backup of the installation with a password, repeated (builds.md §4.11): into a file the desktop app
 * asks for, else downloaded. For a restore (its token), it marks this installation's state as saved, which the
 * restore waits for.
 */
const { label, restore = undefined } = defineProps<{
    label: string;
    /** The token of the restore, which this saved state is for. */
    restore?: string;
}>();
const emit = defineEmits<{ saved: [] }>();

const { save } = useInstallationBackups();

const password = ref('');
const confirmation = ref('');
const busy = ref(false);
const error = ref<unknown>(null);
const done = ref<string | null>(null);

async function submit(): Promise<void> {
    busy.value = true;
    error.value = null;

    try {
        const result = await save(password.value, confirmation.value, restore);

        if (result.saved || result.download_url) {
            done.value = result.saved ? t('settings.backups.full.saved', { name: result.saved }) : t('settings.backups.full.downloaded');
            emit('saved');
        }
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <TextField
            v-model="password"
            :label="t('settings.backups.full.password')"
            :hint="t('settings.backups.full.passwordHint')"
            type="password"
            autocomplete="new-password"
            :error="fieldError(error, 'password')"
        />
        <TextField v-model="confirmation" :label="t('settings.backups.full.repeatPassword')" type="password" autocomplete="new-password" />
        <p v-if="error && !fieldError(error, 'password')" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(error) }}</p>
        <p v-if="done" class="m-0 flex items-center gap-2 text-14 text-positive" role="status"><Icon name="check" :size="18" /> {{ done }}</p>
        <SecondaryButton :busy="busy" :disabled="password.length < 8 || password !== confirmation" @click="submit">{{ label }}</SecondaryButton>
    </div>
</template>
