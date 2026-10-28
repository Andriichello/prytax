<script setup lang="ts">
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { t } from '@/i18n';

/**
 * The export block at the foot of a list (design.md §16.6, rule 10): the heading «Експорт», its `ExportButton`s in a
 * grid of `columns` (two by default), and an optional hint under them.
 */
const { columns = 2, hint = undefined } = defineProps<{
    columns?: 2 | 3;
    hint?: string;
}>();
</script>

<template>
    <section class="mt-1 flex flex-col gap-2">
        <SectionTitle>{{ t('core.common.export') }}</SectionTitle>
        <div class="grid gap-2" :class="columns === 3 ? 'grid-cols-3' : 'grid-cols-2'">
            <slot />
        </div>
        <p v-if="hint" class="m-0 px-0.5 text-12 leading-[1.45] text-ink-3">{{ hint }}</p>
    </section>
</template>
