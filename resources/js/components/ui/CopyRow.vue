<script setup lang="ts">
import { ref } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';

/**
 * A requisite of an account: its label, the value as shown (masked), and a button copying the full value.
 */
const { label, value, shown = undefined, figure = true, wrap = false } = defineProps<{
    label: string;
    value: string;
    /** What is shown instead of the value, such as the masked number. */
    shown?: string;
    figure?: boolean;
    /** Long values (a payment's purpose) wrap instead of being cut. */
    wrap?: boolean;
}>();

const copied = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = true;
        clearTimeout(timer);
        timer = setTimeout(() => (copied.value = false), 1500);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <div class="flex items-center gap-3 py-3">
        <span class="flex min-w-0 flex-1 flex-col gap-0.5">
            <span class="text-12 text-ink-3">{{ label }}</span>
            <span class="text-15" :class="[figure ? 'num' : '', wrap ? 'break-words whitespace-normal!' : 'truncate']">{{ shown ?? value }}</span>
        </span>
        <span v-if="copied" class="text-12 text-positive" role="status">{{ t('shell.ui.copied') }}</span>
        <button type="button" :aria-label="t('shell.ui.copyLabel', { label })" class="flex size-11 shrink-0 items-center justify-center text-ink-2" @click="copy">
            <Icon :name="copied ? 'check' : 'copy'" :size="20" />
        </button>
    </div>
</template>
