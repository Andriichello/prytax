<script setup lang="ts">
import Icon from '@/components/ui/Icon.vue';

/**
 * The white, bordered button next to a primary one ("Зберегти", "Скасувати", "Видалити" in red).
 */
const { type = 'button', busy = false, disabled = false, tone = 'default' } = defineProps<{
    type?: 'button' | 'submit';
    busy?: boolean;
    disabled?: boolean;
    tone?: 'default' | 'negative';
}>();
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || busy"
        :aria-busy="busy ? 'true' : undefined"
        class="flex h-13 w-full items-center justify-center gap-2 rounded-button border border-line-input bg-surface text-16 font-medium disabled:opacity-60"
        :class="tone === 'negative' ? 'text-negative' : 'text-ink'"
    >
        <Icon v-if="busy" name="spinner" :size="20" class="animate-spin" />
        <slot />
    </button>
</template>
