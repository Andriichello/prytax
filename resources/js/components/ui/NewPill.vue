<script setup lang="ts">
import type { RouteLocationRaw } from 'vue-router';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';

/**
 * The accent pill of a list's header (design.md §16.6, rule 6): a 20 px plus and «Новий», or its own `label` («+
 * Надходження»).
 */
const { to, label = undefined } = defineProps<{
    to: RouteLocationRaw;
    label?: string;
}>();
</script>

<template>
    <RouterLink
        :to="to"
        class="flex h-11 shrink-0 items-center gap-1.5 rounded-full bg-accent pr-4 pl-3 text-15 font-semibold text-white no-underline hover:bg-accent-hover hover:text-white"
    >
        <Icon name="plus" :size="20" />
        {{ label ?? t('core.common.new') }}
    </RouterLink>
</template>
