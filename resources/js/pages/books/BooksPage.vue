<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api, apiUrl } from '@/api/client';
import type { ApiResponse, IncomeBookYear, TaxesBookYear } from '@/api/types';
import IncomeBookTable from '@/components/books/IncomeBookTable.vue';
import IncomeLimitBand from '@/components/books/IncomeLimitBand.vue';
import TaxBookCard from '@/components/books/TaxBookCard.vue';
import TaxCumulativeCard from '@/components/books/TaxCumulativeCard.vue';
import TaxesBand from '@/components/books/TaxesBand.vue';
import SubScreen from '@/components/layout/SubScreen.vue';
import ExportBlock from '@/components/ui/ExportBlock.vue';
import ExportButton from '@/components/ui/ExportButton.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SectionTitle from '@/components/ui/SectionTitle.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import SideNote from '@/components/ui/SideNote.vue';
import Translated from '@/components/ui/Translated.vue';
import { useBreakpoint } from '@/composables/useBreakpoint';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { t } from '@/i18n';
import { dateParts, formatDate, today } from '@/utils/dates';
import { formatAmount } from '@/utils/money';

/**
 * Книги обліку (M-Books-Income, M-Books-Taxes, M-Books-TaxesByTax, M-BooksStates; design.md §16.7): the ФОП's
 * books of a year, Доходи (the income book by month, with its receipts) and Податки (the year's taxes cumulative by
 * quarter, or by tax). The year chip and the grouping are the only controls; the tab, the year and the grouping live
 * in the URL (`?tab=income|taxes&year=&group=quarter|tax`). A year before the registration says so, with a way to the
 * first one. The foot exports the tab of the year as a PDF; on desktop its button stands at the right of the section's
 * title, over the table or the cards.
 */
type Tab = 'income' | 'taxes';
type Grouping = 'quarter' | 'tax';

const route = useRoute();
const router = useRouter();
const { profile, profileId, path } = useCurrentProfile();
const { isDesktop } = useBreakpoint();

const currentYear = dateParts(today()).year;
const registeredYear = computed(() => (profile.value?.registered_at ? dateParts(profile.value.registered_at).year : currentYear));

const tab = computed<Tab>({
    get: () => (route.query.tab === 'taxes' ? 'taxes' : 'income'),
    set: (value) => void router.replace({ query: { ...route.query, tab: value } }),
});
const year = computed<number>({
    get: () => {
        const asked = Number(route.query.year);

        return Number.isInteger(asked) && asked >= 2000 && asked <= currentYear ? asked : currentYear;
    },
    set: (value) => void router.replace({ query: { ...route.query, year: String(value) } }),
});
const group = computed<Grouping>({
    get: () => (route.query.group === 'tax' ? 'tax' : 'quarter'),
    set: (value) => void router.replace({ query: { ...route.query, group: value } }),
});

const years = computed(() => {
    const first = Math.min(registeredYear.value, year.value);

    return Array.from({ length: currentYear - first + 1 }, (_, index) => currentYear - index);
});

const beforeRegistration = computed(() => year.value < registeredYear.value);

const TABS = computed(() => [
    { value: 'income' as Tab, label: t('accounting.books.income') },
    { value: 'taxes' as Tab, label: t('accounting.books.taxes') },
]);
const GROUPS = computed(() => [
    { value: 'quarter' as Grouping, label: t('accounting.books.byQuarter') },
    { value: 'tax' as Grouping, label: t('accounting.books.byTax') },
]);

const { data: income, loading: incomeLoading, error: incomeError, reload: reloadIncome } = useLoader(async (signal) => (tab.value === 'income' && !beforeRegistration.value
    ? (await api.get<ApiResponse<IncomeBookYear>>(path(`books/${year.value}/income`), {}, signal)).data
    : null), [tab, year, profileId]);
const { data: taxes, loading: taxesLoading, error: taxesError, reload: reloadTaxes } = useLoader(async (signal) => (tab.value === 'taxes' && !beforeRegistration.value
    ? (await api.get<ApiResponse<TaxesBookYear>>(path(`books/${year.value}/taxes`), {}, signal)).data
    : null), [tab, year, profileId]);

const loading = computed(() => (tab.value === 'income' ? incomeLoading.value && !income.value : taxesLoading.value && !taxes.value));
const error = computed(() => (tab.value === 'income' ? incomeError.value : taxesError.value));
const reload = (): Promise<void> => (tab.value === 'income' ? reloadIncome() : reloadTaxes());

const subtitle = computed(() => (profile.value ? t('accounting.books.subtitle', { name: profile.value.short_name, group: profile.value.tax_group }) : ''));
const pdf = computed(() => apiUrl(path(`books/${year.value}/${tab.value}/export?format=pdf`)));

function chooseYear(event: Event): void {
    year.value = Number((event.target as HTMLSelectElement).value);
}
</script>

<template>
    <SubScreen :title="t('accounting.books.title')" :subtitle="subtitle" :back="{ name: 'home' }">
        <template #aside>
            <SideNote :text="t('settings.sideNotes.books')" :accordion="false" />
        </template>

        <template #action>
            <label class="relative mr-2 flex h-11 shrink-0 items-center gap-1.5 rounded-full border border-line-input bg-surface pr-3 pl-3.5 text-15 font-medium">
                <Icon name="calendar" :size="18" />
                <span class="num">{{ year }}</span>
                <Icon name="chevronDown" :size="16" class="text-ink-3" />
                <select
                    :value="year"
                    :aria-label="t('accounting.books.year')"
                    class="absolute inset-0 cursor-pointer appearance-none opacity-0"
                    @change="chooseYear"
                >
                    <option v-for="option in years" :key="option" :value="option">{{ option }}</option>
                </select>
            </label>
        </template>
        <template #toolbar>
            <SegmentedControl v-model="tab" :label="t('accounting.books.tabs')" size="lg" :options="TABS" />
        </template>

        <section v-if="beforeRegistration" class="flex flex-col items-start gap-2 rounded-card border border-line bg-surface p-4">
            <h2 class="m-0 text-17 font-semibold">
                {{ tab === 'income' ? t('accounting.books.beforeIncome', { year }) : t('accounting.books.beforeTaxes', { year }) }}
            </h2>
            <p class="m-0 text-14 leading-[1.45] text-ink-2">
                {{ tab === 'income'
                    ? t('accounting.books.beforeIncomeHint', { date: formatDate(profile?.registered_at) })
                    : t('accounting.books.beforeTaxesHint', { date: formatDate(profile?.registered_at) }) }}
            </p>
            <button type="button" class="mt-1 flex h-11 items-center rounded-button border border-line-input bg-surface px-4 text-15 font-medium text-accent" @click="year = registeredYear">
                {{ t('accounting.books.goToYear', { year: registeredYear }) }}
            </button>
        </section>

        <LoadState v-else :loading="loading" :error="error" @retry="reload">
            <template v-if="tab === 'income' && income">
                <IncomeLimitBand
                    :ytd="income.ytd"
                    :percent="income.limit.percent"
                    :cap="income.limit.cap"
                    :left="income.limit.left"
                    :group="profile?.tax_group ?? 3"
                />
                <section v-if="!income.has_receipts" class="flex flex-col gap-1.5 rounded-card border border-line bg-surface p-4">
                    <h2 class="m-0 text-16 font-semibold">{{ t('accounting.books.emptyYear', { year }) }}</h2>
                    <p class="m-0 text-14 leading-[1.45] text-ink-3">{{ t('accounting.books.emptyYearHint') }}</p>
                </section>
                <template v-else>
                    <div class="flex items-center justify-between gap-3">
                        <SectionTitle>
                            {{ t('accounting.books.incomeBook') }}
                            <template v-if="!isDesktop" #aside><span class="num text-ink-2">{{ t('accounting.books.newestFirst') }}</span></template>
                        </SectionTitle>
                        <ExportButton v-if="isDesktop" :href="pdf" class="h-10! px-3.5!">PDF</ExportButton>
                    </div>
                    <IncomeBookTable :book="income" :wide="isDesktop" />
                </template>
                <p class="m-0 px-0.5 text-13 leading-[1.45] text-ink-3">{{ t('accounting.books.incomeNote') }}</p>
            </template>

            <template v-if="tab === 'taxes' && taxes">
                <TaxesBand :totals="taxes.totals" />
                <div class="flex items-center justify-between gap-3 pl-0.5">
                    <span class="text-13 font-semibold tracking-[0.04em] text-ink-3 uppercase">{{ t('accounting.books.group') }}</span>
                    <SegmentedControl v-model="group" class="w-50 shrink-0" :label="t('accounting.books.group')" :options="GROUPS" />
                </div>
                <template v-if="group === 'quarter'">
                    <div class="flex items-center justify-between gap-3">
                        <SectionTitle>
                            {{ t('accounting.books.cumulativeTitle') }}
                            <template v-if="!isDesktop" #aside><span class="num text-ink-2">{{ t('accounting.books.newestFirst') }}</span></template>
                        </SectionTitle>
                        <ExportButton v-if="isDesktop" :href="pdf" class="h-10! px-3.5!">PDF</ExportButton>
                    </div>
                    <div :class="isDesktop ? 'grid grid-cols-2 items-start gap-4' : 'contents'">
                        <TaxCumulativeCard v-for="period in taxes.cumulative" :key="period.to_quarter" :period="period" :year="year" />
                    </div>
                    <p class="m-0 px-0.5 text-13 leading-[1.45] text-ink-3">
                        <Translated :text="t('accounting.books.taxesNote', { esv: formatAmount(taxes.esv_monthly ?? '0') })">
                            <template #plus><span class="text-warning">{{ t('accounting.books.plusAmount') }}</span></template>
                        </Translated>
                    </p>
                </template>
                <template v-else>
                    <div class="flex items-center justify-between gap-3">
                        <SectionTitle>
                            {{ t('accounting.books.byTaxes') }}
                            <template v-if="!isDesktop" #aside><span class="num text-ink-2">{{ year }}</span></template>
                        </SectionTitle>
                        <ExportButton v-if="isDesktop" :href="pdf" class="h-10! px-3.5!">PDF</ExportButton>
                    </div>
                    <div class="flex gap-3.5 px-0.5 text-12 text-ink-2">
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-[2px] bg-positive" />{{ t('accounting.books.legendPaid') }}</span>
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-[2px] bg-sent-bar" />{{ t('accounting.books.legendSent') }}</span>
                        <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-[2px] bg-line-input" />{{ t('accounting.books.legendUnpaid') }}</span>
                    </div>
                    <div :class="isDesktop ? '@container' : 'contents'">
                        <div :class="isDesktop ? 'grid grid-cols-1 items-start gap-4 @min-[620px]:grid-cols-2 @min-[940px]:grid-cols-3' : 'contents'">
                            <TaxBookCard v-for="tax in taxes.by_tax" :key="tax.tax_type" :tax="tax" />
                        </div>
                    </div>
                </template>
            </template>

            <ExportBlock v-if="(tab === 'income' && income && !(isDesktop && income.has_receipts)) || (tab === 'taxes' && taxes && !isDesktop)">
                <ExportButton :href="pdf">PDF</ExportButton>
            </ExportBlock>
        </LoadState>
    </SubScreen>
</template>
