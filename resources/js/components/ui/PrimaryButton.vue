<script setup lang="ts">
import Icon from '@/components/ui/Icon.vue';

/**
 * The cobalt call to action of a form or sheet: 52 px, full width; while `busy`, it shows a spinner and can't be
 * pressed again.
 */
const { type = 'button', busy = false, disabled = false } = defineProps<{
    type?: 'button' | 'submit';
    busy?: boolean;
    disabled?: boolean;
}>();
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || busy"
        :aria-busy="busy ? 'true' : undefined"
        class="flex h-13 w-full items-center justify-center gap-2 rounded-button bg-accent text-16 font-semibold text-white hover:bg-accent-hover disabled:opacity-60"
    >
        <Icon v-if="busy" name="spinner" :size="20" class="animate-spin" />
        <slot />
    </button>
</template>
