<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import Icon from '@/components/ui/Icon.vue';
import { t } from '@/i18n';

/**
 * The accent pill of a list's header (design.md §16.6, rule 6): a 20 px plus and «Новий», or its own `label` («+
 * Надходження»). On desktop it is the page's primary action, in the page header left of the 📖 | 🔔 capsule
 * (§17.6). A link to `to`, or, without it, a button that emits `click` («Запросити», «Створити копію зараз»).
 */
const { to = undefined, label = undefined } = defineProps<{
    to?: RouteLocationRaw;
    label?: string;
}>();

defineEmits<{ click: [] }>();
</script>

<template>
    <component
        :is="to ? RouterLink : 'button'"
        :to="to"
        :type="to ? undefined : 'button'"
        class="flex h-11 shrink-0 items-center gap-1.5 rounded-full bg-accent pr-4 pl-3 text-15 font-semibold text-white no-underline hover:bg-accent-hover hover:text-white"
        @click="$emit('click')"
    >
        <Icon name="plus" :size="20" />
        {{ label ?? t('core.common.new') }}
    </component>
</template>
