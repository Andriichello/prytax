<script setup lang="ts">
import Icon from '@/components/ui/Icon.vue';

/**
 * A badge of a root screen's filter row (design.md §16.2): the period, dark with a calendar icon, or another filter,
 * white with a chevron. A tap opens the screen's «Фільтри» drawer.
 */
const { label, period = false } = defineProps<{
    label: string;
    period?: boolean;
}>();

const emit = defineEmits<{ click: [] }>();
</script>

<template>
    <button
        type="button"
        class="flex h-11 shrink-0 items-center gap-2 rounded-full px-4 text-15 whitespace-nowrap"
        :class="period ? 'bg-ink text-white' : 'border border-line-input bg-surface text-ink'"
        @click="emit('click')"
    >
        <Icon v-if="period" name="calendar" :size="18" />
        <span :class="period ? 'num font-medium' : ''">{{ label }}</span>
        <Icon name="chevronDown" :size="16" />
    </button>
</template>
