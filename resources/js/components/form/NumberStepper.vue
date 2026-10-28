<script setup lang="ts">
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';

/**
 * A whole number between `min` and `max`, with − and + on its sides and a label with its hint on the left
 * (M-BK-Policy-Retention, the days, weeks and months kept).
 */
const { label, hint = undefined, min = 0, max } = defineProps<{
    label: string;
    hint?: string;
    min?: number;
    max: number;
}>();

const model = defineModel<number>({ required: true });

function step(delta: number): void {
    model.value = Math.min(max, Math.max(min, model.value + delta));
}
</script>

<template>
    <div class="flex items-center justify-between gap-3 py-2">
        <span class="flex min-w-0 flex-col">
            <span class="text-15 font-medium">{{ label }}</span>
            <span v-if="hint" class="text-12 text-ink-3">{{ hint }}</span>
        </span>
        <span class="flex shrink-0 items-center gap-1.5" role="group" :aria-label="label">
            <button
                type="button"
                :aria-label="t('shell.ui.decrease')"
                :disabled="model <= min"
                class="flex size-10 items-center justify-center rounded-full border border-line-input bg-surface text-ink disabled:text-switch-off"
                @click="step(-1)"
            >
                <Icon name="minus" :size="18" />
            </button>
            <span class="num w-9 text-center text-17 font-semibold" aria-live="polite">{{ model }}</span>
            <button
                type="button"
                :aria-label="t('shell.ui.increase')"
                :disabled="model >= max"
                class="flex size-10 items-center justify-center rounded-full border border-line-input bg-surface text-ink disabled:text-switch-off"
                @click="step(1)"
            >
                <Icon name="plus" :size="18" />
            </button>
        </span>
    </div>
</template>
