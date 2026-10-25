<script setup lang="ts">
import ToggleSwitch from '@/components/ui/ToggleSwitch.vue';

/**
 * A card with a switch (design.md §16.3): its title, an optional line under it (where a value comes from, or what it
 * means, such as «Неактивний не пропонується в нових інвойсах»), and the switch on the right. The `aside` slot puts
 * an action, such as «Скинути», inline in the line.
 */
const { title, sub = undefined, disabled = false } = defineProps<{
    title: string;
    sub?: string;
    disabled?: boolean;
}>();

const checked = defineModel<boolean>({ required: true });
</script>

<template>
    <div class="flex min-h-14 items-center justify-between gap-3 rounded-control border border-line bg-surface px-3.5 py-2">
        <span class="flex min-w-0 flex-col gap-0.5">
            <span class="text-15 font-medium">{{ title }}</span>
            <span v-if="sub || $slots.aside" class="text-12 text-ink-3">{{ sub }}<slot name="aside" /></span>
        </span>
        <ToggleSwitch v-model="checked" :label="title" :disabled="disabled" />
    </div>
</template>
