<script setup lang="ts">
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';

/**
 * ‹ IV квартал 2026 › — steps through periods like a calendar; a side, which can't go further, is disabled. A
 * `sublabel` (such as the deadline) shows under the period.
 */
const { label, sublabel = undefined, canPrevious = true, canNext = true, previousLabel = undefined, nextLabel = undefined } = defineProps<{
    label: string;
    sublabel?: string;
    canPrevious?: boolean;
    canNext?: boolean;
    previousLabel?: string;
    nextLabel?: string;
}>();

const emit = defineEmits<{ previous: []; next: [] }>();
</script>

<template>
    <div role="group" :aria-label="label" class="flex items-center gap-2 rounded-button border border-line bg-surface p-1">
        <button
            type="button"
            :aria-label="previousLabel ?? t('shell.ui.previous')"
            :disabled="!canPrevious"
            class="flex size-11 items-center justify-center rounded-[10px] text-ink disabled:text-handle"
            @click="emit('previous')"
        >
            <Icon name="chevronLeft" :size="22" />
        </button>
        <span class="flex flex-1 flex-col items-center" aria-live="polite">
            <span class="text-16 font-semibold">{{ label }}</span>
            <span v-if="sublabel" class="text-13 text-ink-2">{{ sublabel }}</span>
        </span>
        <button
            type="button"
            :aria-label="nextLabel ?? t('shell.ui.next')"
            :disabled="!canNext"
            class="flex size-11 items-center justify-center rounded-[10px] text-ink disabled:text-handle"
            @click="emit('next')"
        >
            <Icon name="chevronRight" :size="22" />
        </button>
    </div>
</template>
