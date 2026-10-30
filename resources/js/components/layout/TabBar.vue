<script setup lang="ts">
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import Icon from '@/components/ui/Icon.vue';
import type { IconName } from '@/components/ui/icons';
import { t } from '@/i18n';
import { type TabName, tabPaths } from '@/router';

/**
 * The bottom tab bar: each tab returns to its screen as it was left (`tabPaths`: the filters, and for a page kept
 * alive its scroll), or opens it fresh the first time.
 */
const TABS: { name: TabName; label: string; icon: IconName; to: string }[] = [
    { name: 'home', get label() { return t('shell.nav.home'); }, icon: 'home', to: '/' },
    { name: 'invoices', get label() { return t('shell.nav.invoices'); }, icon: 'invoice', to: '/invoices' },
    { name: 'accounting', get label() { return t('shell.nav.accounting'); }, icon: 'ledger', to: '/accounting' },
    { name: 'taxes', get label() { return t('shell.nav.taxes'); }, icon: 'bank', to: '/taxes' },
    { name: 'more', get label() { return t('shell.more.more'); }, icon: 'menu', to: '/settings' },
];

const route = useRoute();
const active = computed(() => route.meta.tab);
</script>

<template>
    <nav
        :aria-label="t('shell.nav.mainNavigation')"
        class="sticky bottom-0 z-10 flex border-t border-line bg-surface px-1 pt-1.5 pb-[calc(env(safe-area-inset-bottom)+6px)]"
    >
        <RouterLink
            v-for="tab in TABS"
            :key="tab.name"
            :to="tabPaths[tab.name] ?? tab.to"
            :aria-current="active === tab.name ? 'page' : undefined"
            class="flex min-h-13 flex-1 flex-col items-center justify-center gap-[3px] text-11 no-underline"
            :class="active === tab.name ? 'font-semibold text-accent hover:text-accent' : 'font-medium text-ink-3 hover:text-ink'"
        >
            <Icon :name="tab.icon" />
            {{ tab.label }}
        </RouterLink>
    </nav>
</template>
