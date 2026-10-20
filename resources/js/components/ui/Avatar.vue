<script setup lang="ts">
/**
 * Round initials: dark for the current profile, light for others, dashed for an inactive one.
 */
const { initials, variant = 'light', size = 40 } = defineProps<{
    initials: string;
    variant?: 'dark' | 'light' | 'dashed';
    size?: number;
}>();
</script>

<template>
    <span
        class="flex shrink-0 items-center justify-center rounded-full font-semibold"
        :class="{
            'bg-ink text-on-ink': variant === 'dark',
            'bg-line text-ink': variant === 'light',
            'border border-dashed border-handle': variant === 'dashed',
        }"
        :style="{ width: `${size}px`, height: `${size}px`, fontSize: size < 38 ? '13px' : '14px' }"
        aria-hidden="true"
    >
        {{ initials }}
    </span>
</template>
