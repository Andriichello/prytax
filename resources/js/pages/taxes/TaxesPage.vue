<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import TabScreen from '@/components/layout/TabScreen.vue';
import TaxAccrualView from '@/components/taxes/TaxAccrualView.vue';
import TaxHistoryView from '@/components/taxes/TaxHistoryView.vue';
import FilterBadge from '@/components/ui/FilterBadge.vue';
import Icon from '@/components/ui/Icon.vue';
import PeriodFilterSheet, { type PeriodFilter } from '@/components/ui/PeriodFilterSheet.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { currentQuarter, quarterLabel } from '@/utils/dates';

/**
 * Податки (M-Taxes, M-TaxesHistory, design.md §16.5): «Нарахування» of one quarter (the taxes, then the receipts with
 * their ЄП and ВЗ), or «Історія сплат» of one year (`?mode=history`). The year and quarter live in the URL; the period
 * badge opens «Фільтри», and «+ ЄСВ» opens Сплата ЄСВ on the quarter's earliest month not fully paid.
 */
type Mode = 'accrual' | 'history';

const route = useRoute();
const router = useRouter();
const { can } = useSession();

const now = currentQuarter();
const mode = computed<Mode>(() => (route.query.mode === 'history' ? 'history' : 'accrual'));
const year = computed(() => Number(route.query.year) || now.year);
const quarter = computed(() => {
    const value = Number(route.query.quarter);

    return value >= 1 && value <= 4 ? value : year.value === now.year ? now.quarter : 4;
});

function update(changes: Record<string, string | number | undefined>): void {
    const query = Object.fromEntries(Object.entries({ ...route.query, ...changes }).filter(([, value]) => value !== undefined));

    void router.replace({ query });
}

const modeOptions = computed<{ value: Mode; label: string }[]>(() => [
    { value: 'accrual', label: t('accounting.taxes.accrual') },
    { value: 'history', label: t('accounting.taxes.history') },
]);
const modeModel = computed({
    get: () => mode.value,
    set: (value: Mode) => update({ mode: value === 'history' ? 'history' : undefined }),
});

const filterOpen = ref(false);
const periodLabel = computed(() => (mode.value === 'history'
    ? t('shell.filters.wholeYearBadge', { year: year.value })
    : `${quarterLabel(quarter.value)} ${year.value}`));

function applyFilter(filter: PeriodFilter): void {
    update({ year: filter.year, quarter: mode.value === 'history' ? route.query.quarter as string | undefined : filter.quarter });
}
</script>

<template>
    <TabScreen :title="t('shell.nav.taxes')">
        <template #title-action>
            <RouterLink
                v-if="can('create', 'tax-payments')"
                :to="{ name: 'esv-payment-new', query: { year, quarter } }"
                class="flex h-11 items-center gap-1.5 rounded-full bg-accent pr-4 pl-3 text-15 font-semibold text-white no-underline hover:bg-accent-hover hover:text-white"
            >
                <Icon name="plus" :size="20" />
                {{ t('accounting.taxes.esv') }}
            </RouterLink>
        </template>
        <template #switcher>
            <SegmentedControl v-model="modeModel" :label="t('accounting.taxes.view')" size="lg" :options="modeOptions" />
        </template>
        <template #filters>
            <div class="-mx-4 flex gap-2 overflow-x-auto px-4">
                <FilterBadge :label="periodLabel" period @click="filterOpen = true" />
            </div>
        </template>

        <TaxAccrualView v-if="mode === 'accrual'" :year="year" :quarter="quarter" />
        <TaxHistoryView v-else :year="year" />

        <PeriodFilterSheet
            v-model:open="filterOpen"
            :value="{ year, quarter }"
            :year-only="mode === 'history'"
            @apply="applyFilter"
        />
    </TabScreen>
</template>
