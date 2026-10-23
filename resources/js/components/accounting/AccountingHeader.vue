<script setup lang="ts">
import { computed } from 'vue';
import { t } from '@/i18n';

/**
 * The tabs of Облік — Квартали, ЄСВ, Книга доходів — keeping the chosen year between them.
 */
export type AccountingTab = 'quarters' | 'esv' | 'income-book';

const { active, year } = defineProps<{
    active: AccountingTab;
    year: number;
}>();

const tabs = computed(() => [
    { name: 'quarters' as const, label: t('Квартали'), to: { name: 'accounting', params: { year } } },
    { name: 'esv' as const, label: t('ЄСВ'), to: { name: 'accounting-esv', query: { year } } },
    { name: 'income-book' as const, label: t('Книга доходів'), to: { name: 'income-book', query: { year } } },
]);
</script>

<template>
    <nav :aria-label="t('Розділи обліку')" class="-mt-1 flex gap-5 border-b border-line">
        <RouterLink
            v-for="tab in tabs"
            :key="tab.name"
            :to="tab.to"
            :aria-current="tab.name === active ? 'page' : undefined"
            class="-mb-px flex min-h-11 items-center border-b-2 text-15 no-underline"
            :class="tab.name === active ? 'border-accent font-semibold text-ink hover:text-ink' : 'border-transparent text-ink-2 hover:text-ink'"
        >
            {{ tab.label }}
        </RouterLink>
    </nav>
</template>
