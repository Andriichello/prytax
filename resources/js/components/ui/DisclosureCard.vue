<script setup lang="ts">
import { ref } from 'vue';
import Icon from '@/components/ui/Icon.vue';

/**
 * A card, which opens in place: its title and hint, then what it holds (the default slot). What it holds is made the
 * first time it opens and kept after it closes, so a half-done form isn't lost.
 */
const { title, hint = undefined } = defineProps<{ title: string; hint?: string }>();
const open = defineModel<boolean>('open', { default: false });

const opened = ref(open.value);

function toggle(event: Event): void {
    open.value = (event.target as HTMLDetailsElement).open;
    opened.value = opened.value || open.value;
}
</script>

<template>
    <details class="group rounded-card border border-line bg-surface" :open="open" @toggle="toggle">
        <summary class="flex min-h-14 cursor-pointer list-none items-center justify-between gap-3 p-4 [&::-webkit-details-marker]:hidden">
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="text-15 font-semibold">{{ title }}</span>
                <span v-if="hint" class="text-13 text-ink-3">{{ hint }}</span>
            </span>
            <Icon name="chevronDown" :size="18" class="shrink-0 transition-transform group-open:rotate-180" />
        </summary>
        <div v-if="opened" class="flex flex-col gap-4 border-t border-line p-4">
            <slot />
        </div>
    </details>
</template>
