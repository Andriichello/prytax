<script setup lang="ts">
import { computed } from 'vue';

/**
 * Steps of a status (Чернетка → Готовий → …): a bar per step, cobalt up to the current one, whose label is bold.
 */
const { steps, current, label } = defineProps<{
    steps: { key: string; label: string }[];
    current: string;
    label: string;
}>();

const index = computed(() => steps.findIndex((step) => step.key === current));
</script>

<template>
    <ol class="m-0 grid list-none gap-1 p-0" :style="{ gridTemplateColumns: `repeat(${steps.length}, minmax(0, 1fr))` }" :aria-label="label">
        <li
            v-for="(step, position) in steps"
            :key="step.key"
            class="flex flex-col gap-1.5"
            :aria-current="position === index ? 'step' : undefined"
        >
            <span class="h-1 rounded-full" :class="position <= index ? 'bg-accent' : 'bg-muted'" />
            <span class="text-12" :class="position === index ? 'font-semibold text-ink' : 'text-ink-3'">{{ step.label }}</span>
        </li>
    </ol>
</template>
