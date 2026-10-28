<script setup lang="ts">
import Icon from '@/components/ui/Icon.vue';
import type { IconName } from '@/components/ui/icons';

/**
 * A button of the export block (design.md §16.6, rule 10): 48 px, the radius of every secondary button, an icon and a
 * short label («CSV», «XLSX», «PDF»). With `href` it downloads that file; otherwise it is a button.
 */
const { href = undefined, icon = 'download' } = defineProps<{
    href?: string;
    icon?: IconName;
}>();

const classes = 'flex h-12 min-w-0 items-center justify-center gap-2 rounded-button border border-line-input bg-surface px-2 text-15 font-medium text-ink no-underline hover:text-ink';
</script>

<template>
    <a v-if="href" :href="href" download :class="classes">
        <Icon :name="icon" :size="18" /><span class="truncate"><slot /></span>
    </a>
    <button v-else type="button" :class="classes">
        <Icon :name="icon" :size="18" /><span class="truncate"><slot /></span>
    </button>
</template>
