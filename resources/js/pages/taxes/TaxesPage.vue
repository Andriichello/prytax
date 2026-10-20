<script setup lang="ts">
import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '@/api/client';
import type { ApiResponse, TaxReconciliation, TaxType } from '@/api/types';
import YearSelect from '@/components/accounting/YearSelect.vue';
import TabScreen from '@/components/layout/TabScreen.vue';
import TaxMonthsView from '@/components/taxes/TaxMonthsView.vue';
import TaxQuarterView from '@/components/taxes/TaxQuarterView.vue';
import TaxReceiptsView from '@/components/taxes/TaxReceiptsView.vue';
import Icon from '@/components/ui/Icon.vue';
import LoadState from '@/components/ui/LoadState.vue';
import SegmentedControl from '@/components/ui/SegmentedControl.vue';
import { useCurrentProfile } from '@/composables/useCurrentProfile';
import { useLoader } from '@/composables/useLoader';
import { useSession } from '@/composables/useSession';
import { currentQuarter, formatDate, quarterLabel } from '@/utils/dates';
import { TAX_TYPES } from '@/utils/statuses';

/**
 * Податки (S-Taxes, S-TaxesByPayment): a tax (ЄП, ВЗ, ЄСВ) of a year by quarter, by month, or — ЄП and ВЗ — by
 * receipt. The tax, view, year and quarter live in the URL (`/taxes?year=`, as the API links).
 */
type View = 'receipts' | 'months' | 'quarters';

const TAXES: TaxType[] = ['single_tax', 'military_levy', 'social_contribution'];

const route = useRoute();
const router = useRouter();
const { profileId, path } = useCurrentProfile();
const { can } = useSession();

const now = currentQuarter();
const year = computed(() => Number(route.query.year) || now.year);
const tax = computed<TaxType>(() => (TAXES.includes(route.query.tax as TaxType) ? route.query.tax as TaxType : 'single_tax'));
const view = computed<View>(() => {
    const value = route.query.view as View;

    if (value === 'receipts' && tax.value === 'social_contribution') {
        return 'quarters';
    }

    return value === 'receipts' || value === 'months' ? value : 'quarters';
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

const { data: reconciliation, error, loading, reload } = useLoader(async (signal) => {
    const { data } = await api.get<ApiResponse<TaxReconciliation>>(path(`taxes/${year.value}`), { tax_type: tax.value }, signal);

    return data;
}, [profileId, year, tax]);

const dueOfQuarter = computed(() => reconciliation.value?.quarters.find((item) => item.quarter === quarter.value)?.due_at ?? null);

/** The declaration to open: the last quarter, which has ended. */
const declaration = computed(() => (now.quarter > 1 ? { year: now.year, quarter: now.quarter - 1 } : { year: now.year - 1, quarter: 4 }));
</script>

<template>
    <TabScreen title="Податки">
        <template #action>
            <RouterLink
                :to="{ name: 'declaration', params: declaration }"
                :aria-label="`Декларація за ${quarterLabel(declaration.quarter)} ${declaration.year}`"
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
                Сплата
            </RouterLink>
        </template>

        <div class="-mt-1 flex items-end justify-between gap-3 border-b border-line">
            <nav aria-label="Податок" class="flex gap-5">
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
            label="Подання"
            :options="tax === 'social_contribution'
                ? [{ value: 'months', label: 'Місяць' }, { value: 'quarters', label: 'Квартал' }]
                : [{ value: 'receipts', label: 'Надходження' }, { value: 'months', label: 'Місяць' }, { value: 'quarters', label: 'Квартал' }]"
        />

        <template v-if="view === 'receipts'">
            <div class="flex items-center justify-between gap-3">
                <label class="relative flex min-h-11 items-center rounded-control border border-line-input bg-surface">
                    <span class="sr-only">Квартал</span>
                    <select v-model.number="quarterModel" class="h-11 appearance-none bg-transparent pr-9 pl-3.5 text-16">
                        <option v-for="value in 4" :key="value" :value="value">{{ quarterLabel(value) }} {{ year }}</option>
                    </select>
                    <Icon name="chevronDown" :size="16" class="pointer-events-none absolute right-3" />
                </label>
                <span v-if="dueOfQuarter" class="text-14 text-ink-3">ЄП + ВЗ · до <span class="num">{{ formatDate(dueOfQuarter) }}</span></span>
            </div>
            <TaxReceiptsView :year="year" :quarter="quarter" />
        </template>

        <TaxMonthsView v-else-if="view === 'months'" :year="year" :tax-type="tax" />

        <LoadState v-else :loading="loading && !reconciliation" :error="error" @retry="reload">
            <TaxQuarterView v-if="reconciliation" :reconciliation="reconciliation" />
        </LoadState>
    </TabScreen>
</template>
