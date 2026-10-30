<script setup lang="ts">
import { computed } from 'vue';
import Icon from '@/components/ui/Icon.vue';
import type { IconName } from '@/components/ui/icons';
import { t } from '@/i18n';
import { tabPaths } from '@/router';

/**
 * The sections as cards on desktop's Головна, above its invoices: Інвойси, Облік, Податки, Книги and Налаштування,
 * each with its icon, its name and what it holds. A tab's card goes back where the tab was left, as the sidebar does.
 * Five a row on a wide page, three or two as it narrows.
 */
const cards = computed<{ key: string; label: string; hint: string; icon: IconName; to: string }[]>(() => [
    { key: 'invoices', label: t('shell.nav.invoices'), hint: t('invoices.home.navInvoices'), icon: 'invoice', to: tabPaths.invoices ?? '/invoices' },
    { key: 'accounting', label: t('shell.nav.accounting'), hint: t('invoices.home.navAccounting'), icon: 'ledger', to: tabPaths.accounting ?? '/accounting' },
    { key: 'taxes', label: t('shell.nav.taxes'), hint: t('invoices.home.navTaxes'), icon: 'bank', to: tabPaths.taxes ?? '/taxes' },
    { key: 'books', label: t('shell.nav.booksShort'), hint: t('invoices.home.navBooks'), icon: 'book', to: '/books' },
    { key: 'settings', label: t('shell.nav.settings'), hint: t('invoices.home.navSettings'), icon: 'settings', to: '/settings' },
]);
</script>

<template>
    <nav :aria-label="t('invoices.home.navLabel')" class="@container">
        <ul class="m-0 grid list-none grid-cols-2 gap-3 p-0 @min-[560px]:grid-cols-3 @min-[880px]:grid-cols-5">
            <li v-for="card in cards" :key="card.key" class="flex">
                <RouterLink
                    :to="card.to"
                    class="flex w-full flex-col gap-3 rounded-card border border-line bg-surface p-4 text-ink no-underline transition-colors hover:border-accent hover:text-ink"
                >
                    <span class="flex size-10 items-center justify-center rounded-[10px] bg-accent-soft text-accent-hover">
                        <Icon :name="card.icon" :size="22" />
                    </span>
                    <span class="flex flex-col gap-1">
                        <span class="text-16 font-semibold">{{ card.label }}</span>
                        <span class="text-13 leading-[1.4] text-ink-3">{{ card.hint }}</span>
                    </span>
                </RouterLink>
            </li>
        </ul>
    </nav>
</template>
