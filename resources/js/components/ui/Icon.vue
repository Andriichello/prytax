<script setup lang="ts">
import type { PropType } from 'vue';
import { ICONS, type IconName } from './icons';

defineProps({
    name: { type: String as PropType<IconName>, required: true },
    size: { type: Number, default: 24 },
});
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="1.8"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        class="shrink-0"
        v-html="ICONS[name]"
    />
</template>
