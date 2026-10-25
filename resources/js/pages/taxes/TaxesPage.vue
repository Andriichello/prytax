<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, TaxLedger } from '@/api/types';
import TabScreen from '@/components/layout/TabScreen.vue';
import TaxFilterSheet from '@/components/taxes/TaxFilterSheet.vue';
import TaxLedgerMonth from '@/components/taxes/TaxLedgerMonth.vue';
import TaxSummaryCard from '@/components/taxes/TaxSummaryCard.vue';
import EmptyState from '@/components/ui/EmptyState.vue';
import FilterBadge from '@/components/ui/FilterBadge.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { currentQuarter } from '@/utils/dates';
import {
    filterFromQuery,
    isEveryTax,
    LEDGER_STATUSES,
    periodBadge,
    queryOfFilter,
    statusesBadge,
    type TaxLedgerFilter,
    taxesBadge,
} from '@/utils/taxLedger';

/**
 * Податки (M-Taxes, design.md §8.5, §16.5): one ledger of the period. «Підсумок за період» sums each tax by the
 * quarter it belongs to; «Податки за місяцями» lists, newest first, what is still owed (a receipt's ЄП and ВЗ in its
 * month, ЄСВ in its own month) and every payment in the month it was made. The three badges (the period, the taxes,
 * the statuses) open one «Фільтри» drawer, and the filter lives in the URL. Each row opens its payment; ЄСВ is paid
 * from its rows, ahead too. App keeps it alive behind an opened payment, so going back keeps the place.
 */
defineOptions({ name: 'TaxesPage' });

const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const now = currentQuarter();

// The filter follows the URL only while Податки is shown: kept alive behind a payment, it keeps its own
const ledgerRoute = route.name;
const filter = ref(filterFromQuery(route.query, now));

watch(() => route.query, (query) => {
    const next = filterFromQuery(query, now);

    if (route.name === ledgerRoute && JSON.stringify(next) !== JSON.stringify(filter.value)) {
        filter.value = next;
    }
});

const filterOpen = ref(false);

function applyFilter(next: TaxLedgerFilter): void {
    void router.replace({ query: { ...route.query, quarter: undefined, mode: undefined, view: undefined, ...queryOfFilter(next) } });
}

const { data: ledger, error, loading, reload } = useLoader(async (signal) => {
    const { year, quarters, taxes, statuses } = filter.value;
    const response = await api.get<ApiResponse<TaxLedger>>(path(`taxes/${year}/ledger`), {
        quarter: quarters,
        tax_type: isEveryTax(taxes) ? undefined : taxes,
        status: statuses.length === LEDGER_STATUSES.length ? undefined : statuses,
    }, signal);

    return response.data;
}, [profileId, () => JSON.stringify(filter.value)]);

const canPay = computed(() => can('create', 'tax-payments'));
const isEmpty = computed(() => ledger.value !== null && ledger.value.summary.rows.length === 0 && ledger.value.months.length === 0);
</script>

<template>
    <TabScreen :title="t('shell.nav.taxes')">
        <template #filters>
            <div class="scrollbar-none -mx-4 flex gap-2 overflow-x-auto px-4">
                <FilterBadge :label="periodBadge(filter)" period @click="filterOpen = true" />
                <FilterBadge :label="taxesBadge(filter.taxes)" @click="filterOpen = true" />
                <FilterBadge :label="statusesBadge(filter.statuses)" @click="filterOpen = true" />
            </div>
        </template>

        <LoadState :loading="loading && !ledger" :error="error" @retry="reload">
            <template v-if="ledger">
                <EmptyState v-if="isEmpty" :text="t('accounting.taxes.empty')" />
                <template v-else>
                    <section v-if="ledger.summary.rows.length > 0" class="flex flex-col gap-2.5">
                        <SectionTitle rule>{{ t('accounting.taxes.summaryTitle') }}</SectionTitle>
                        <TaxSummaryCard :summary="ledger.summary" :quarters="filter.quarters.length || 4" />
                    </section>
                    <section v-if="ledger.months.length > 0" class="flex flex-col gap-2.5">
                        <SectionTitle rule>{{ t('accounting.taxes.byMonth') }}</SectionTitle>
                        <TaxLedgerMonth v-for="month in ledger.months" :key="month.month" :month="month" :can-pay="canPay" />
                        <p class="m-0 text-13 leading-[1.45] text-ink-3">{{ t('accounting.taxes.footnote') }}</p>
                    </section>
                </template>
            </template>
        </LoadState>

        <TaxFilterSheet v-model:open="filterOpen" :value="filter" @apply="applyFilter" />
    </TabScreen>
</template>
