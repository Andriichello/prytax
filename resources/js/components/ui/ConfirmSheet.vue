<script setup lang="ts">
import BottomSheet from '@/components/ui/BottomSheet.vue';
import PrimaryButton from '@/components/ui/PrimaryButton.vue';
import SecondaryButton from '@/components/ui/SecondaryButton.vue';
import { t } from '@/i18n';

/**
 * A question before an action that can't be taken back easily: its title, an explanation (the default slot), and
 * "Скасувати" next to the action, which is red for destructive ones.
 */
const { title, confirmLabel, busy = false, destructive = false, error = null } = defineProps<{
    title: string;
    confirmLabel: string;
    busy?: boolean;
    destructive?: boolean;
    error?: string | null;
}>();

const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ confirm: [] }>();
</script>

<template>
    <BottomSheet v-model:open="open" :title="title">
        <div class="flex flex-col gap-3 text-15 leading-normal text-ink-2">
            <slot />
        </div>
        <p v-if="error" class="m-0 text-14 text-negative" role="alert">{{ error }}</p>
        <div class="grid grid-cols-[1fr_1.4fr] gap-2.5 pt-1">
            <SecondaryButton @click="open = false">{{ t('Скасувати') }}</SecondaryButton>
            <SecondaryButton v-if="destructive" tone="negative" :busy="busy" @click="emit('confirm')">{{ confirmLabel }}</SecondaryButton>
            <PrimaryButton v-else :busy="busy" @click="emit('confirm')">{{ confirmLabel }}</PrimaryButton>
        </div>
    </BottomSheet>
</template>
