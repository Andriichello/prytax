<script setup lang="ts">
import { ref, watch } from 'vue';
import BottomSheet from '@/components/ui/BottomSheet.vue';
import Icon from '@/components/ui/Icon.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { t } from '@/i18n';
import { errorMessage } from '@/utils/errors';

/**
 * «Створити копію зараз» (M-BK-Run, D-BK-Run; backups.md §4.8): every ФОП of the organization (the default) or only
 * the current one, what a manual copy keeps and how many runs are left today; at none, «Створити» is off.
 */
const { profilesCount, size, currentName, left, busy = false, error = null } = defineProps<{
    profilesCount: number;
    /** The size of the newest archives, when there are any. */
    size: string | null;
    currentName: string;
    left: number;
    busy?: boolean;
    error?: unknown;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ create: [scope: 'all' | 'current'] }>();

const scope = ref<'all' | 'current'>('all');

watch(open, (isOpen) => {
    if (isOpen) {
        scope.value = 'all';
    }
});

const options = (): { value: 'all' | 'current'; title: string; sub: string }[] => [
    {
        value: 'all',
        title: t('settings.backups.allProfilesOption'),
        sub: size ? t('settings.backups.allProfilesMeta', { count: profilesCount, size }) : t('settings.backups.allProfilesCount', { count: profilesCount }),
    },
    { value: 'current', title: t('settings.backups.currentProfileOption'), sub: currentName },
];
</script>

<template>
    <BottomSheet v-model:open="open" :title="t('settings.backups.createCopyNow')">
        <div role="radiogroup" :aria-label="t('settings.backups.createCopyNow')" class="flex flex-col gap-2">
            <button
                v-for="option in options()"
                :key="option.value"
                type="button"
                role="radio"
                :aria-checked="scope === option.value ? 'true' : 'false'"
                class="flex items-center gap-3 rounded-button border px-4 py-3 text-left"
                :class="scope === option.value ? 'border-2 border-accent bg-accent-soft px-[15px] py-[11px]' : 'border-line bg-surface'"
                @click="scope = option.value"
            >
                <span class="flex size-5 shrink-0 items-center justify-center rounded-full border-2" :class="scope === option.value ? 'border-accent' : 'border-line-input'">
                    <span v-if="scope === option.value" class="size-2.5 rounded-full bg-accent" />
                </span>
                <span class="flex min-w-0 flex-col">
                    <span class="text-15 font-semibold">{{ option.title }}</span>
                    <span class="truncate text-13 text-ink-3">{{ option.sub }}</span>
                </span>
            </button>
        </div>
        <p class="m-0 flex gap-2 rounded-control bg-muted px-3.5 py-3 text-13 leading-[1.45] text-ink-2">
            <Icon name="info" :size="18" class="shrink-0" />
            <span>{{ left > 0 ? t('settings.backups.manualInfo', { left }) : t('settings.backups.limitReached') }}</span>
        </p>
        <p v-if="error" class="m-0 text-14 text-negative" role="alert">{{ errorMessage(error) }}</p>
        <template #footer>
            <div class="grid grid-cols-[1fr_1.4fr] gap-2">
                <SecondaryButton @click="open = false">{{ t('core.common.cancel') }}</SecondaryButton>
                <PrimaryButton :busy="busy" :disabled="left <= 0" @click="emit('create', scope)">{{ t('settings.backups.create') }}</PrimaryButton>
            </div>
        </template>
    </BottomSheet>
</template>
