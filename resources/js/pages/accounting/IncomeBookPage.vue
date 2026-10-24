<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, apiUrl } from '@/api/client';
import type { ApiResponse, IncomeBook } from '@/api/types';
import AccountingHeader from '@/components/accounting/AccountingHeader.vue';
import YearSelect from '@/components/accounting/YearSelect.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { t } from '@/i18n';
import { currentQuarter, monthName, quarterLabel } from '@/utils/dates';
import { formatAmount } from '@/utils/money';

/**
 * Облік, Книга доходів (S-IncomeBook): the year's income by month with its running total and quarter subtotals, and
 * the exports of the year.
 */
const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();

const now = currentQuarter();
const year = computed(() => Number(route.query.year) || now.year);
const yearModel = computed({
    get: () => year.value,
    set: (value: number) => void router.replace({ query: { year: value } }),
});

const { data: book, error, loading, reload } = useLoader(async (signal) => {
    const { data } = await api.get<ApiResponse<IncomeBook>>(path(`income-book/${year.value}`), undefined, signal);

    return data;
}, [profileId, year]);

/** Months up to the current one, each quarter closed by its subtotal once its last month is shown. */
const lines = computed(() => {
    const result: ({ kind: 'month'; month: IncomeBook['months'][number] } | { kind: 'quarter'; quarter: IncomeBook['quarters'][number] })[] = [];

    for (const month of book.value?.months ?? []) {
        if (month.is_future) {
            continue;
        }

        result.push({ kind: 'month', month });

        const quarter = book.value?.quarters.find((item) => item.quarter === month.quarter);

        if (month.month % 3 === 0 && quarter && !month.is_current) {
            result.push({ kind: 'quarter', quarter });
        }
    }

    return result;
});

const exports = computed(() => [
    { label: t('accounting.incomeBook.yearRecords'), hint: 'CSV', href: apiUrl(path(`income-book/${year.value}/export`)) },
    { label: t('accounting.incomeBook.allReceiptsTaxes'), hint: 'CSV', href: apiUrl(path(`accounting/${year.value}/export`)) },
]);

const grid = 'grid grid-cols-[1fr_auto_auto] items-baseline gap-x-4 px-4';
</script>

<template>
    <TabScreen :title="t('shell.nav.accounting')">
        <template #title-action>
            <YearSelect v-model="yearModel" />
        </template>

        <AccountingHeader active="income-book" :year="year" />

        <p class="m-0 text-14 leading-[1.45] text-ink-3">
            {{ t('accounting.incomeBook.intro') }}
        </p>

        <LoadState :loading="loading && !book" :error="error" @retry="reload">
            <section v-if="book" class="flex flex-col overflow-hidden rounded-card border border-line bg-surface" :aria-label="t('accounting.tabs.incomeBook')">
                <div :class="grid" class="border-b border-divider bg-paper-light py-2.5 text-13 font-semibold text-ink-3">
                    <span>{{ t('core.common.month') }}</span>
                    <span class="text-right">{{ t('accounting.incomeBook.incomeUah') }}</span>
                    <span class="w-28 text-right">{{ t('accounting.incomeBook.ytd') }}</span>
                </div>
                <template v-for="(line, index) in lines" :key="index">
                    <div v-if="line.kind === 'month'" :class="[grid, index && lines[index - 1]?.kind === 'month' ? 'border-t border-divider' : '']" class="py-3.5">
                        <span class="flex flex-col">
                            <span class="text-16">{{ monthName(line.month.month) }}</span>
                            <span v-if="line.month.is_current" class="text-12 text-ink-3">{{ t('accounting.incomeBook.current') }}</span>
                        </span>
                        <span class="num text-right text-16">{{ formatAmount(line.month.income) }}</span>
                        <span class="num w-28 text-right text-16 text-ink-2">{{ formatAmount(line.month.cumulative) }}</span>
                    </div>
                    <div v-else :class="grid" class="bg-paper py-2.5">
                        <span class="text-15 font-semibold">{{ quarterLabel(line.quarter.quarter) }}</span>
                        <span class="num text-right text-15 font-bold">{{ formatAmount(line.quarter.income) }}</span>
                        <span class="w-28" />
                    </div>
                </template>
                <div :class="grid" class="border-t border-line bg-paper-light py-3 text-15 font-semibold">
                    <span>{{ t('core.common.year') }}</span>
                    <span class="num text-right">{{ formatAmount(book.total) }}</span>
                    <span class="w-28" />
                </div>
            </section>
        </LoadState>

        <section class="flex flex-col gap-2">
            <SectionTitle>{{ t('accounting.incomeBook.export') }}</SectionTitle>
            <a
                v-for="file in exports"
                :key="file.label"
                :href="file.href"
                download
                class="flex min-h-14 items-center gap-3 rounded-card border border-line bg-surface px-4 text-16 text-ink no-underline hover:text-ink"
            >
                <Icon name="download" :size="20" />
                <span class="flex-1">{{ file.label }}</span>
                <span class="text-14 text-ink-3">{{ file.hint }}</span>
            </a>
        </section>
    </TabScreen>
</template>
