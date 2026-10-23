<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, TaxGrouping, TaxReconciliation, TaxType } from '@/api/types';
import YearSelect from '@/components/accounting/YearSelect.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import TaxMonthsView from '@/components/taxes/TaxMonthsView.vue';
import TaxQuarterView from '@/components/taxes/TaxQuarterView.vue';
import TaxReceiptsView from '@/components/taxes/TaxReceiptsView.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import Translated from '@/components/ui/Translated.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { t } from '@/i18n';
import { currentQuarter, formatDate, quarterLabel } from '@/utils/dates';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * Податки (S-Taxes, S-TaxesByPayment): a tax (ЄП, ВЗ, ЄСВ) of a year by quarter, by month, or — ЄП and ВЗ — by
 * receipt. The tax, view, year and quarter live in the URL (`/taxes?year=`, as the API links); without a view, the
 * profile's `taxes.default_grouping` chooses it.
 */
type View = 'receipts' | 'months' | 'quarters';

const TAXES: TaxType[] = ['single_tax', 'military_levy', 'social_contribution'];
const VIEWS: View[] = ['receipts', 'months', 'quarters'];
const VIEW_OF_GROUPING: Record<TaxGrouping, View> = { payment: 'receipts', month: 'months', quarter: 'quarters' };

const route = useRoute();
const router = useRouter();
const { profileId, path, settings } = useCurrentProfile();
const { can } = useSession();

const now = currentQuarter();
const year = computed(() => Number(route.query.year) || now.year);
const tax = computed<TaxType>(() => (TAXES.includes(route.query.tax as TaxType) ? route.query.tax as TaxType : 'single_tax'));
const view = computed<View>(() => {
    const asked = route.query.view as View;
    const value = VIEWS.includes(asked) ? asked : VIEW_OF_GROUPING[settings.value?.taxes.default_grouping ?? 'quarter'];

    return value === 'receipts' && tax.value === 'social_contribution' ? 'quarters' : value;
});
const quarter = computed(() => {
    const value = Number(route.query.quarter);

    return value >= 1 && value <= 4 ? value : year.value === now.year ? now.quarter : 4;
});

function update(changes: Record<string, string | number>): void {
    void router.replace({ query: { ...route.query, ...changes } });
}

const yearModel = computed({ get: () => year.value, set: (value: number) => update({ year: value }) });
const viewModel = computed({ get: () => view.value, set: (value: View) => update({ view: value }) });
const quarterModel = computed({ get: () => quarter.value, set: (value: number) => update({ quarter: value }) });

/** The views of a tax: ЄСВ isn't owed per receipt, so it has no view by receipt. */
const viewOptions = computed<{ value: View; label: string }[]>(() => [
    ...(tax.value === 'social_contribution' ? [] : [{ value: 'receipts' as const, label: t('Надходження') }]),
    { value: 'months', label: t('Місяць') },
    { value: 'quarters', label: t('Квартал') },
]);

const { data: reconciliation, error, loading, reload } = useLoader(async (signal) => {
    const { data } = await api.get<ApiResponse<TaxReconciliation>>(path(`taxes/${year.value}`), { tax_type: tax.value }, signal);

    return data;
}, [profileId, year, tax]);

const dueOfQuarter = computed(() => reconciliation.value?.quarters.find((item) => item.quarter === quarter.value)?.due_at ?? null);

/** The declaration to open: the last quarter, which has ended. */
const declaration = computed(() => (now.quarter > 1 ? { year: now.year, quarter: now.quarter - 1 } : { year: now.year - 1, quarter: 4 }));
</script>

<template>
    <TabScreen :title="t('Податки')">
        <template #action>
            <RouterLink
                :to="{ name: 'declaration', params: declaration }"
                :aria-label="t('Декларація за {quarter} {year}', { quarter: quarterLabel(declaration.quarter), year: declaration.year })"
                class="flex size-11 shrink-0 items-center justify-center rounded-full border border-line bg-surface text-ink hover:text-ink"
            >
                <Icon name="calendar" :size="22" />
            </RouterLink>
        </template>
        <template #title-action>
            <RouterLink
                v-if="can('create', 'tax-payments')"
                :to="{ name: 'tax-payment-new', query: { tax, year } }"
                class="flex h-11 items-center gap-1.5 rounded-full bg-accent pr-4 pl-3 text-15 font-semibold text-white no-underline hover:bg-accent-hover hover:text-white"
            >
                <Icon name="plus" :size="20" />
                {{ t('Сплата') }}
            </RouterLink>
        </template>

        <div class="-mt-1 flex items-end justify-between gap-3 border-b border-line">
            <nav :aria-label="t('Податок')" class="flex gap-5">
                <button
                    v-for="type in TAXES"
                    :key="type"
                    type="button"
                    :aria-current="type === tax ? 'page' : undefined"
                    class="-mb-px flex min-h-11 items-center border-b-2 text-15"
                    :class="type === tax ? 'border-accent font-semibold text-ink' : 'border-transparent text-ink-2'"
                    @click="update({ tax: type })"
                >
                    {{ TAX_TYPES[type] }}
                </button>
            </nav>
            <div class="mb-1.5"><YearSelect v-model="yearModel" /></div>
        </div>

        <SegmentedControl
            v-model="viewModel"
            :label="t('Подання')"
            :options="viewOptions"
        />

        <template v-if="view === 'receipts'">
            <div class="flex items-center justify-between gap-3">
                <label class="relative flex min-h-11 items-center rounded-control border border-line-input bg-surface">
                    <span class="sr-only">{{ t('Квартал') }}</span>
                    <select v-model.number="quarterModel" class="h-11 appearance-none bg-transparent pr-9 pl-3.5 text-16">
                        <option v-for="value in 4" :key="value" :value="value">{{ quarterLabel(value) }} {{ year }}</option>
                    </select>
                    <Icon name="chevronDown" :size="16" class="pointer-events-none absolute right-3" />
                </label>
                <span v-if="dueOfQuarter" class="text-14 text-ink-3"><Translated :text="t('ЄП + ВЗ · до {date}')"><template #date><span class="num">{{ formatDate(dueOfQuarter) }}</span></template></Translated></span>
            </div>
            <TaxReceiptsView :year="year" :quarter="quarter" />
        </template>

        <TaxMonthsView v-else-if="view === 'months'" :year="year" :tax-type="tax" />

        <LoadState v-else :loading="loading && !reconciliation" :error="error" @retry="reload">
            <TaxQuarterView v-if="reconciliation" :reconciliation="reconciliation" />
        </LoadState>
    </TabScreen>
</template>
