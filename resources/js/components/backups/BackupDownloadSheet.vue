<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { api } from '@/api/client';
import type { BackupRecord } from '@/api/types';
import TextField from '@/components/form/TextField.vue';
import Avatar from '@/components/ui/Avatar.vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import ToggleSwitch from '@/components/ui/ToggleSwitch.vue';
import { t } from '@/i18n';
import { formatBytes, kyivFullDate, kyivTime } from '@/utils/backups';
import { errorMessage, fieldError } from '@/utils/errors';

/**
 * Downloading a copy (M-BK-Download, D-BK-Download; backups.md §4.6, §4.8): whose copy and what it holds; the amber
 * warning that the archive's data are decrypted; «Захистити паролем», off by default (the owner, 2026-10-28), with the
 * password and its hint; then the download, which starts at once with the signed link. A copy skipped as unchanged
 * downloads the archive it stands for. Errors (the hourly limit) show inline.
 */
const { backup, initials, name } = defineProps<{
    backup: BackupRecord | null;
    initials: string;
    name: string;
}>();

const open = defineModel<boolean>('open', { required: true });

const protect = ref(false);
const password = ref('');
const reveal = ref(false);
const busy = ref(false);
const error = ref<unknown>(null);

watch(open, (isOpen) => {
    if (isOpen) {
        protect.value = false;
        password.value = '';
        reveal.value = false;
        error.value = null;
    }
});

/** The archive downloaded: the backup's own, or the one an unchanged backup stands for. */
const target = computed(() => (backup?.status === 'skipped_unchanged' && backup.previous
    ? { id: backup.previous.id, bytes: backup.previous.bytes, documents: backup.previous.documents, created_at: backup.previous.created_at }
    : backup ? { id: backup.id, bytes: backup.bytes, documents: backup.documents, created_at: backup.created_at } : null));

const what = computed(() => (target.value ? t('settings.backups.downloadWhat', {
    date: kyivFullDate(target.value.created_at),
    time: kyivTime(target.value.created_at),
    size: formatBytes(target.value.bytes),
    content: target.value.documents ? t('settings.backups.csvAnd', { documents: t('core.counts.documents', { count: target.value.documents }) }) : t('settings.backups.csvOnly'),
}) : ''));

async function download(): Promise<void> {
    if (!target.value) {
        return;
    }

    busy.value = true;
    error.value = null;

    try {
        const { data } = await api.post<{ data: { url: string } }>(`organization/backups/${target.value.id}/download`, {
            password: protect.value && password.value ? password.value : null,
        });
        open.value = false;
        window.location.href = data.url;
    } catch (caught) {
        error.value = caught;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <BottomSheet v-model:open="open" :title="t('settings.backups.downloadTitle')">
        <div class="flex items-center gap-3">
            <Avatar :initials="initials" variant="dark" :size="40" />
            <span class="flex min-w-0 flex-col">
                <span class="truncate text-16 font-semibold">{{ name }}</span>
                <span class="text-13 text-ink-3">{{ what }}</span>
            </span>
        </div>
        <div class="flex gap-2.5 rounded-control bg-warning-soft px-3.5 py-3 text-13 leading-[1.45] text-warning">
            <Icon name="lock" :size="18" class="shrink-0" />
            <span><b>{{ t('settings.backups.openDataTitle') }}</b> {{ t('settings.backups.openDataText') }}</span>
        </div>
        <label class="flex items-center justify-between gap-3 rounded-control border border-line bg-surface px-4 py-3">
            <span class="text-15 font-medium">{{ t('settings.backups.protect') }}</span>
            <ToggleSwitch v-model="protect" />
        </label>
        <template v-if="protect">
            <div class="relative">
                <TextField
                    v-model="password"
                    :label="t('settings.backups.password')"
                    :type="reveal ? 'text' : 'password'"
                    :error="fieldError(error, 'password')"
                />
                <button
                    type="button"
                    :aria-label="t('settings.backups.showPassword')"
                    class="absolute right-1 bottom-1 flex size-11 items-center justify-center text-ink-2"
                    @click="reveal = !reveal"
                >
                    <Icon name="eye" :size="20" />
                </button>
            </div>
            <p class="m-0 text-12 leading-[1.45] text-ink-3">{{ t('settings.backups.passwordHint') }}</p>
        </template>
        <p v-if="error && !fieldError(error, 'password')" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(error) }}</p>
        <template #footer>
            <div class="flex flex-col gap-2.5">
                <div class="grid grid-cols-[1fr_1.4fr] gap-2">
                    <SecondaryButton @click="open = false">{{ t('core.common.cancel') }}</SecondaryButton>
                    <PrimaryButton :busy="busy" :disabled="protect && password.length < 8" @click="download">
                        <Icon name="download" :size="18" /> {{ t('settings.backups.downloadZip') }}
                    </PrimaryButton>
                </div>
                <p class="m-0 text-center text-12 text-ink-3">{{ t('settings.backups.downloadNote') }}</p>
            </div>
        </template>
    </BottomSheet>
</template>
