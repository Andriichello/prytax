<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import Icon from '@/components/ui/Icon.vue';
import type { IconName } from '@/components/ui/icons';
import type { TabName } from '@/router';

const TABS: { name: TabName; label: string; icon: IconName; to: string }[] = [
    { name: 'home', label: 'Головна', icon: 'home', to: '/' },
    { name: 'invoices', label: 'Інвойси', icon: 'invoice', to: '/invoices' },
    { name: 'accounting', label: 'Облік', icon: 'ledger', to: '/accounting' },
    { name: 'taxes', label: 'Податки', icon: 'bank', to: '/taxes' },
    { name: 'more', label: 'Ще', icon: 'menu', to: '/more' },
];

const route = useRoute();
const active = computed(() => route.meta.tab);
</script>

<template>
    <nav
        aria-label="Основна навігація"
        class="sticky bottom-0 z-10 flex border-t border-line bg-surface px-1 pt-1.5 pb-[calc(env(safe-area-inset-bottom)+6px)]"
    >
        <RouterLink
            v-for="tab in TABS"
            :key="tab.name"
            :to="tab.to"
            :aria-current="active === tab.name ? 'page' : undefined"
            class="flex min-h-13 flex-1 flex-col items-center justify-center gap-[3px] text-11 no-underline"
            :class="active === tab.name ? 'font-semibold text-accent hover:text-accent' : 'font-medium text-ink-3 hover:text-ink'"
        >
            <Icon :name="tab.icon" />
            {{ tab.label }}
        </RouterLink>
    </nav>
</template>
